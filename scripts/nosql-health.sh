#!/usr/bin/env bash
# One health command for the 4 local NoSQL DBs (Gate 1 / GitHub #1).
# Reports READY / NOT READY per DB using native CLIs inside the containers.
#
# Usage:
#   ./scripts/nosql-health.sh              # single pass, exit 0 iff 4/4 READY
#   ./scripts/nosql-health.sh --wait 120   # poll every 5s up to 120s (for `up -d && health`)
#
# Credential resolution per DB: shell env first, then .env in repo root,
# then the same dev-defaults as docker-compose.yml. Export MONGO_*/NEO4J_*/REDIS_*
# (or edit .env) if you changed them from the defaults.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Read KEY from .env (repo root) without sourcing it (values may contain spaces).
dotenv_get() {
  local key="$1" file="$ROOT/.env" line
  [ -f "$file" ] || return 0
  line="$(grep -E "^${key}=" "$file" | tail -1 || true)"
  [ -n "$line" ] || return 0
  line="${line#*=}"
  line="${line%\"}"; line="${line#\"}"
  line="${line%\'}"; line="${line#\'}"
  printf '%s' "$line"
}

# var_or <ENV_NAME> <DOTENV_KEY> <DEFAULT>  (env wins, then .env, then default)
var_or() {
  local env_name="$1" dotenv_key="$2" default="$3" v="" indirect=""
  indirect="${!env_name:-}" || true
  if [ -n "$indirect" ]; then printf '%s' "$indirect"; return 0; fi
  v="$(dotenv_get "$dotenv_key")"
  if [ -n "$v" ] && [ "$v" != "null" ]; then printf '%s' "$v"; return 0; fi
  printf '%s' "$default"
}

MONGO_USER="$(var_or MONGO_ROOT_USER MONGO_ROOT_USER vietfeed)"
MONGO_PASS="$(var_or MONGO_ROOT_PASSWORD MONGO_ROOT_PASSWORD vietfeed-mongo-dev)"
NEO4J_USER="$(var_or NEO4J_USER NEO4J_USER neo4j)"
NEO4J_PASS="$(var_or NEO4J_PASSWORD NEO4J_PASSWORD vietfeed-neo4j-dev)"
REDIS_PASS="$(var_or REDIS_PASSWORD REDIS_PASSWORD vietfeed-redis-dev)"

WAIT_SECS=0
if [ "${1:-}" = "--wait" ]; then WAIT_SECS="${2:-120}"; fi

check_mongo() {
  docker exec vietfeed-mongo mongosh --quiet -u "$MONGO_USER" -p "$MONGO_PASS" \
    --eval "db.adminCommand('connectionStatus').authInfo.authenticatedUsers.length" 2>/dev/null | grep -q 1
}

check_cassandra() {
  docker exec vietfeed-cassandra cqlsh -e 'DESCRIBE KEYSPACES' >/dev/null 2>&1
}

check_neo4j() {
  docker exec vietfeed-neo4j cypher-shell -u "$NEO4J_USER" -p "$NEO4J_PASS" 'RETURN 1' >/dev/null 2>&1
}

check_redis() {
  docker exec vietfeed-redis redis-cli -a "$REDIS_PASS" ping 2>/dev/null | grep -q PONG
}

run_once() {
  local ready=0 total=4 status
  for db in mongo cassandra neo4j redis; do
    if "check_$db"; then status="READY"; ready=$((ready + 1)); else status="NOT READY"; fi
    echo "$db: $status"
  done
  echo "---"
  echo "$ready/$total READY"
  [ "$ready" -eq "$total" ]
}

if [ "$WAIT_SECS" -gt 0 ]; then
  deadline=$((SECONDS + WAIT_SECS))
  while true; do
    if run_once; then exit 0; fi
    [ "$SECONDS" -ge "$deadline" ] && { echo "TIMEOUT after ${WAIT_SECS}s"; exit 1; }
    sleep 5
  done
else
  run_once
fi
