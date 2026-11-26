#!/usr/bin/env bash
# One-time system setup for the VietFeed public demo. Run ONCE with sudo from the demo worktree:
#   sudo ~/vietfeed-demo/deploy/install-system.sh [--passwordless-start]
# Idempotent: safe to re-run after `git pull` to re-install changed config files.
# --passwordless-start adds /etc/sudoers.d/vietfeed-demo so `deploy/start-all.sh` can start/stop ONLY the
# demo services (php8.4-fpm, caddy, cloudflared, vietfeed-demo-queue, vietfeed-demo-scheduler) without a password.
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo $0 $*" >&2; exit 1; }
DEMO_USER="${SUDO_USER:-dolakhoa}"
DEMO_HOME="$(getent passwd "$DEMO_USER" | cut -d: -f6)"
DEMO_DIR="$DEMO_HOME/vietfeed-demo"
[ -f "$DEMO_DIR/artisan" ] && [ -f "$DEMO_DIR/.env" ] || { echo "Demo worktree or its .env not found at $DEMO_DIR" >&2; exit 1; }
PASSWORDLESS=0; [ "${1:-}" = "--passwordless-start" ] && PASSWORDLESS=1

step() { printf '\n==> %s\n' "$*"; }

step "Packages (acl)"
command -v setfacl >/dev/null || { apt-get update && apt-get install -y acl; }
for bin in php-fpm8.4 caddy cloudflared; do command -v "$bin" >/dev/null || { echo "Missing $bin: install it first (deploy/README.md 2.1)" >&2; exit 1; }; done

step "php-fpm pool"
install -m 0644 "$DEMO_DIR/deploy/php-fpm/vietfeed-demo.conf" /etc/php/8.4/fpm/pool.d/vietfeed-demo.conf
install -m 0644 "$DEMO_DIR/deploy/php-fpm/99-vietfeed-demo.ini" /etc/php/8.4/fpm/conf.d/99-vietfeed-demo.ini
php-fpm8.4 -t
systemctl enable php8.4-fpm >/dev/null
systemctl restart php8.4-fpm

step "Caddy"
setfacl -m u:caddy:x "$DEMO_HOME"
install -d -o caddy -g caddy -m 0755 /var/log/caddy
install -m 0644 "$DEMO_DIR/deploy/caddy/Caddyfile" /etc/caddy/Caddyfile
runuser -u caddy -- caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null
chown -R caddy:caddy /var/log/caddy
systemctl enable caddy >/dev/null
systemctl restart caddy

step "Queue and scheduler units"
install -m 0644 "$DEMO_DIR/deploy/systemd/vietfeed-demo-queue.service" /etc/systemd/system/vietfeed-demo-queue.service
install -m 0644 "$DEMO_DIR/deploy/systemd/vietfeed-demo-scheduler.service" /etc/systemd/system/vietfeed-demo-scheduler.service
systemctl daemon-reload
systemctl enable vietfeed-demo-queue vietfeed-demo-scheduler >/dev/null
systemctl restart --no-block vietfeed-demo-queue vietfeed-demo-scheduler   # they wait for the stores; start-all.sh brings those up

step "cloudflared"
systemctl enable cloudflared >/dev/null 2>&1 || echo "cloudflared service not installed yet: run the 'sudo cloudflared service install <token>' command from the Cloudflare dashboard"

if [ "$PASSWORDLESS" -eq 1 ]; then
  step "Passwordless start/stop for the demo services only"
  units="php8.4-fpm caddy cloudflared vietfeed-demo-queue vietfeed-demo-scheduler"
  tmp="$(mktemp)"
  {
    echo "# Managed by VietFeed deploy/install-system.sh: lets $DEMO_USER start/stop/restart ONLY the demo services."
    for action in start stop restart; do
      echo "$DEMO_USER ALL=(root) NOPASSWD: /usr/bin/systemctl $action $units"
    done
  } > "$tmp"
  visudo -cf "$tmp" >/dev/null
  install -m 0440 "$tmp" /etc/sudoers.d/vietfeed-demo
  rm -f "$tmp"
fi

step "Status"
systemctl is-active php8.4-fpm caddy cloudflared vietfeed-demo-queue vietfeed-demo-scheduler | paste -sd' ' - || true
echo "Done. Next: $DEMO_DIR/deploy/health.sh --public"
