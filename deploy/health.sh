#!/usr/bin/env bash
# Read-only health check for the VietFeed demo. Usage: deploy/health.sh [--public]
# Store ports follow docker-compose.yml; override like `MONGO_PORT=27018 deploy/health.sh` when they differ.
set -u
PUBLIC=0
[ "${1:-}" = "--public" ] && PUBLIC=1
FAILS=0
ok()   { printf '  OK    %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*"; FAILS=$((FAILS + 1)); }
tcp()  { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }
unit() { [ "$(systemctl is-active "$1" 2>/dev/null)" = "active" ]; }
http() { # http <url> -> status code
  if [ "${2:-}" = "local" ]; then host=(-H 'Host: vietfeed.app'); else host=(); fi
  curl -s -o /dev/null -w '%{http_code}' --max-time 10 "${host[@]}" "$1" 2>/dev/null || true
}

echo "Stores (TCP, 127.0.0.1)"
for pair in "redis-demo:${REDIS_PORT:-6380}" "mongo:${MONGO_PORT:-27018}" "cassandra:${CASSANDRA_PORT:-9042}" "neo4j:${NEO4J_BOLT_PORT:-7687}"; do
  name="${pair%%:*}"; port="${pair##*:}"
  if tcp "$port"; then ok "$name :$port"; else fail "$name :$port not reachable"; fi
done

echo "Services"
if unit php8.4-fpm; then ok "php8.4-fpm active"; else fail "php8.4-fpm not active"; fi
if [ -S /run/php/vietfeed-demo.sock ]; then ok "php-fpm socket /run/php/vietfeed-demo.sock"; else fail "php-fpm socket missing"; fi
if unit caddy; then ok "caddy active"; else fail "caddy not active"; fi
if tcp 8080; then ok "caddy listening on :8080"; else fail "nothing listening on :8080"; fi
for u in vietfeed-demo-queue vietfeed-demo-scheduler; do
  if unit "$u"; then ok "$u active"; else fail "$u not active"; fi
done
if unit cloudflared; then ok "cloudflared active"; else fail "cloudflared not active"; fi

echo "HTTP (local)"
for path in / /up; do
  code="$(http "http://127.0.0.1:8080$path" local)"
  if [ "$code" = "200" ]; then ok "GET $path -> 200"; else fail "GET $path -> ${code:-no response}"; fi
done

# TLS ends at Cloudflare; if proxy headers are lost the app emits http:// asset URLs (mixed content, broken page).
page="$(curl -s --max-time 10 -H 'Host: vietfeed.app' http://127.0.0.1:8080/login 2>/dev/null || true)"
if printf '%s' "$page" | grep -q 'https://vietfeed.app/build/'; then
  ok "/login emits https://vietfeed.app/build/ asset URLs"
else
  fail "/login does not contain https://vietfeed.app/build/ asset URLs (check APP_URL, TRUSTED_PROXIES, Caddy header_up)"
fi

if [ "$PUBLIC" -eq 1 ]; then
  echo "HTTP (public)"
  code="$(http "https://vietfeed.app/")"
  if [ "$code" = "200" ]; then ok "GET https://vietfeed.app/ -> 200"; else fail "GET https://vietfeed.app/ -> ${code:-no response}"; fi
fi

echo "---"
if [ "$FAILS" -eq 0 ]; then echo "HEALTHY"; else echo "$FAILS check(s) FAILED"; fi
[ "$FAILS" -eq 0 ]
