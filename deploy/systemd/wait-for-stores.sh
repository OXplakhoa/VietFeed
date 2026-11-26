#!/usr/bin/env bash
# ExecStartPre helper: wait until the Docker stores accept TCP connections (Docker Desktop is not a systemd
# unit inside WSL, so After=docker.service is not enough). Read-only; exits 1 after the timeout so systemd retries.
# Ports follow docker-compose.yml; override with Environment= lines in the unit if they differ on this machine.
set -u
TIMEOUT="${WAIT_STORES_TIMEOUT:-240}"
HOST="${STORES_HOST:-127.0.0.1}"
PORTS="${WAIT_REDIS_PORT:-6380} ${WAIT_MONGO_PORT:-27018} ${WAIT_CASSANDRA_PORT:-9042} ${WAIT_NEO4J_PORT:-7687}"

deadline=$((SECONDS + TIMEOUT))
for port in $PORTS; do
  until (exec 3<>"/dev/tcp/$HOST/$port") 2>/dev/null; do
    if [ "$SECONDS" -ge "$deadline" ]; then
      echo "wait-for-stores: $HOST:$port not reachable after ${TIMEOUT}s" >&2
      exit 1
    fi
    sleep 3
  done
done
echo "wait-for-stores: all stores reachable"
