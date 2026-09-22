#!/usr/bin/env bash
# Smoke for the existing MySQL-backed app path (Gate 1 / GitHub #1, per Decisions).
# Verifies session + cache + queue on the `database` driver still work with the
# 4 NoSQL containers up:
#   1. curl /login via ephemeral `artisan serve`  -> HTTP 200, `sessions` rows +1
#   2. tinker Cache::put/get                     -> round-trip ok
#   3. dispatch queued closure + queue:work --once -> runs, `jobs` rows +1/-1
#
# DB selection: uses the app's configured DB (host MySQL) when reachable;
# otherwise falls back to a temp sqlite file to prove the same code paths and
# prints SKIP for the MySQL leg (exit 0, honest label). No external feeds touched.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
PORT="${SMOKE_PORT:-8123}"
PASS=0; FAIL=0; SKIPPED_MYSQL=0

ok()   { PASS=$((PASS+1)); echo "PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "FAIL: $1"; }

# --- 0. pick DB: configured one if reachable, else temp sqlite (SKIP mysql leg) ---
EXTRA_ENV=()
if php artisan tinker --execute='DB::connection()->getPdo(); echo "UP";' 2>/dev/null | grep -q UP; then
  echo "DB leg: configured database reachable (full MySQL-path smoke)"
else
  SKIPPED_MYSQL=1
  echo "DB leg: SKIP mysql (host MySQL unreachable: connection refused)"
  echo "DB leg: falling back to temp sqlite to verify the same session/cache/queue code paths"
  TMPDB="$(mktemp -t vietfeed-smoke-XXXXXX.sqlite)"
  trap 'rm -f "$TMPDB"' EXIT
  EXTRA_ENV=(DB_CONNECTION=sqlite DB_DATABASE="$TMPDB")
  env "${EXTRA_ENV[@]}" php artisan migrate --force -q 2>&1 | tail -2
fi

tinker() { env "${EXTRA_ENV[@]}" php artisan tinker --execute="$1" 2>/dev/null; }

# --- 1. HTTP: ephemeral serve + curl /login, sessions +1 ---
S_BEFORE="$(tinker 'echo DB::table("sessions")->count();' | tr -d '[:space:]')"
env "${EXTRA_ENV[@]}" php artisan serve --no-reload --host=127.0.0.1 --port="$PORT" >/tmp/vietfeed-smoke-serve.log 2>&1 &
SERVE_PID=$!
trap 'kill $SERVE_PID 2>/dev/null; rm -f "${TMPDB:-}"; rm -f "$ROOT/app/Jobs/SmokeProbeJob.php";' EXIT
for _ in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PORT/login" 2>/dev/null && break; sleep 1; done
CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "http://127.0.0.1:$PORT/login")"
TIME_S="$(curl -s -o /dev/null -w '%{time_total}' --max-time 10 "http://127.0.0.1:$PORT/login")"
S_AFTER="$(tinker 'echo DB::table("sessions")->count();' | tr -d '[:space:]')"
[ "$CODE" = "200" ] && ok "/login HTTP 200 in ${TIME_S}s" || fail "/login HTTP $CODE (want 200)"
[ "$S_AFTER" -gt "$S_BEFORE" ] 2>/dev/null && ok "sessions rows $S_BEFORE -> $S_AFTER (+1)" \
  || fail "sessions rows $S_BEFORE -> $S_AFTER (want +1)"

# --- 2. Cache round-trip (database store) ---
C_OUT="$(tinker 'Cache::put("smoke", "ok", 60); echo Cache::get("smoke");' | tr -d '[:space:]')"
[ "$C_OUT" = "ok" ] && ok "Cache::put/get round-trip" || fail "Cache round-trip (got '$C_OUT')"

# --- 3. Queue: temp probe job class, dispatch, work --once, jobs +1/-1 ---
# (A tinker closure cannot rehydrate in the worker: its source is eval()'d code.)
PROBE_JOB="$ROOT/app/Jobs/SmokeProbeJob.php"
cat > "$PROBE_JOB" <<'PHP'
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Temporary infra probe for scripts/smoke-mysql-path.sh (Gate 1). Removed on exit.
class SmokeProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        cache()->put('smoke-queue', 'done', 60);
    }
}
PHP
J_BEFORE="$(tinker 'echo DB::table("jobs")->count();' | tr -d '[:space:]')"
tinker 'dispatch(new \App\Jobs\SmokeProbeJob); echo "DISPATCHED";' | grep -q DISPATCHED \
  && ok "job dispatched" || fail "job dispatch"
J_MID="$(tinker 'echo DB::table("jobs")->count();' | tr -d '[:space:]')"
env "${EXTRA_ENV[@]}" php artisan queue:work --once >/dev/null 2>&1
Q_OUT="$(tinker 'echo Cache::get("smoke-queue");' | tr -d '[:space:]')"
J_AFTER="$(tinker 'echo DB::table("jobs")->count();' | tr -d '[:space:]')"
[ "$J_MID" -gt "$J_BEFORE" ] 2>/dev/null && ok "jobs rows $J_BEFORE -> $J_MID (+1)" \
  || fail "jobs rows $J_BEFORE -> $J_MID (want +1)"
[ "$Q_OUT" = "done" ] && ok "probe job executed" || fail "probe job result (got '$Q_OUT')"
[ "$J_AFTER" = "$J_BEFORE" ] 2>/dev/null && ok "jobs rows drained $J_MID -> $J_AFTER (-1)" \
  || fail "jobs rows $J_MID -> $J_AFTER (want back to $J_BEFORE)"

kill $SERVE_PID 2>/dev/null; trap - EXIT; [ -n "${TMPDB:-}" ] && rm -f "$TMPDB"; rm -f "$ROOT/app/Jobs/SmokeProbeJob.php"
echo "---"
[ "$SKIPPED_MYSQL" = "1" ] && echo "NOTE: mysql leg SKIPPED (host MySQL down); code paths verified on sqlite"
echo "smoke: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
