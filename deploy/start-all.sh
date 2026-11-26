#!/usr/bin/env bash
# Start everything the public demo needs, in order, then run the health check.
#   deploy/start-all.sh            start Docker Desktop (if needed), dev stores, demo Redis, services, health check
#   deploy/start-all.sh --update   same, after pulling the latest demo code and rebuilding (migrations, assets, caches)
#   deploy/start-all.sh --stop     stop the demo services (stores keep running for development)
# Never touches dev data: the demo uses Mongo `vietfeed_demo`, Cassandra `vietfeed_demo` and its own Redis on :6380.
set -euo pipefail

DEMO_DIR="$HOME/vietfeed-demo"
DEV_DIR="$HOME/code/VietFeed"
UNITS="php8.4-fpm caddy cloudflared vietfeed-demo-queue vietfeed-demo-scheduler"
MODE="${1:-start}"
case "$MODE" in start|--update|--stop) ;; *) echo "usage: $0 [--update|--stop]" >&2; exit 2;; esac
step() { printf '\n==> %s\n' "$*"; }
cd "$DEMO_DIR"

if [ "$MODE" = "--stop" ]; then
  step "Stopping demo services"
  sudo systemctl stop $UNITS
  docker compose -p vietfeed-demo -f deploy/docker-compose.demo.yml --env-file .env stop || true
  echo "Stopped. https://vietfeed.app now returns an error page until the next start."
  exit 0
fi

step "Docker Desktop"
if ! timeout 10 docker info >/dev/null 2>&1; then
  /mnt/c/Windows/System32/cmd.exe /c start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe" >/dev/null 2>&1 || true
  printf 'waiting for Docker'
  for _ in $(seq 1 60); do timeout 10 docker info >/dev/null 2>&1 && break; printf '.'; sleep 5; done; echo
  if ! timeout 10 docker info >/dev/null 2>&1; then
    stale="/mnt/c/Users/$(cd /mnt/c && /mnt/c/Windows/System32/cmd.exe /c 'echo %USERNAME%' 2>/dev/null | tr -d '\r' || echo '<you>')/AppData/Local/Docker/run/dockerInference"
    echo "Docker did not start. If Docker Desktop shows 'Inference manager ... dockerInference', quit it, delete" >&2
    echo "  $stale" >&2
    echo "and run this script again. Never press 'Reset to factory defaults' (it deletes all database volumes)." >&2
    exit 1
  fi
fi

step "Stores (dev compose project, ports on 127.0.0.1)"
(cd "$DEV_DIR" && docker compose up -d >/dev/null)
docker compose -p vietfeed-demo -f deploy/docker-compose.demo.yml --env-file .env up -d >/dev/null
printf 'waiting for stores'
for _ in $(seq 1 60); do
  n=$( (cd "$DEV_DIR" && docker compose ps --format '{{.Health}}') | grep -cx healthy || true)
  r=$(docker inspect -f '{{.State.Health.Status}}' vietfeed-demo-redis 2>/dev/null || true)
  [ "$n" = 4 ] && [ "$r" = healthy ] && break
  printf '.'; sleep 5
done; echo
[ "$n" = 4 ] && [ "$r" = healthy ] || { echo "Stores not healthy after 5 min (dev healthy=$n/4, demo redis=$r)" >&2; exit 1; }

if [ "$MODE" = "--update" ]; then
  step "Updating demo code (services stopped during the update)"
  sudo systemctl stop $UNITS
  git pull --ff-only
  php artisan optimize:clear
  composer install --no-dev --optimize-autoloader --no-interaction
  php artisan migrate --force
  npm ci --no-audit --no-fund && npm run build
  php artisan optimize
fi

step "Services (sudo; a password prompt appears unless install-system.sh --passwordless-start was used)"
sudo systemctl start $UNITS

step "Health"
deploy/health.sh --public
