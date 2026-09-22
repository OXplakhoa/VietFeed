# STATUS — infra-cassandra-spike

- Branch: `infra/compose-health-1`
- Base commit: `f384f89` (main, "Reorganize repo root docs")
- Classification: Small–Medium (infra only, no prod feature risk; preserved user's
  uncommitted change to `database/seeders/SourceSeeder.php` — still uncommitted)
- Control session: pi-control

## Scouts

- Repo scout: done — greenfield (no compose/dockerfile), PHP ^8.3, no NoSQL clients, session/cache/queue need MySQL healthy
- Test scout: done — Unit/Feature suites on sqlite :memory:; narrowest loop: compose config → artisan --version → single-file test → composer run test

## Tickets

- T1 (compose + health + DBeaver): GitHub #1 — implemented, reviewable checkpoint 2026-09-22 (commit TBD by implement session)
- T2 (Cassandra spike): GitHub #2 — not started — blocked by #1

## Files changed (T1, all new except noted)

- `docker-compose.yml` (new) — mongo 8.0 / cassandra 5.0 / neo4j 5-community / redis 7-alpine; 127.0.0.1 binds, caps + healthchecks per Decisions
- `scripts/nosql-health.sh` (new) — one health cmd, native CLIs, `--wait` flag, exit 0 iff 4/4 READY
- `scripts/smoke-mysql-path.sh` (new) — session/cache/queue smoke per Decisions
- `config/nosql.php` (new) — env-only stubs, no secrets
- `.env.example` (modified) — MONGO_*/CASSANDRA_*/NEO4J_*/REDIS_NOSQL_DB placeholders
- `docs/database/nosql-local.md` (new) — start/inspect/stop/reset/seed, DBeaver notes, native smoke queries
- Local-only (gitignored, NOT committed): `.env` gained NoSQL block; `REDIS_PASSWORD` `null` → `vietfeed-redis-dev` (compose interpolation reads `.env`; literal `null` broke redis AUTH)

## Decisions (grill NEEDS_DECISION → approved 2026-09-22)

1. Ports/bind/creds: all bind 127.0.0.1; default ports (mongo 27017, cql 9042,
   neo4j 7687/7474, redis 6379); MySQL stays on host (not in compose);
   env names MONGO_* / CASSANDRA_* / NEO4J_* / REDIS_* in .env (gitignored),
   placeholders in .env.example; compose ${VAR:-dev-default} throwaway local creds;
   reset = down -v + up + health cmd.
2. Caps are ceilings, not reservations (JVM defaults are the real enemy on 8GB).
   Approved: cassandra 1.5g/1.0 (heap 768M), neo4j 1g/1.0 (heap 512m, pagecache 256m),
   mongo 768m/0.75, redis 256m/0.5 (maxmemory 200mb). Docker Desktop limit 6GB.
   Responsive = 4 READY <= 120s + homepage < 2s + memory_pressure normal +
   no container at limit + composer run test green. Record actual docker stats;
   bump cassandra heap to 1G if 768M GC-flaky (tuning, not failure).
3. Smoke: scripts/smoke-mysql-path.sh — curl /login (sessions +1), tinker
   Cache::put/get, queue push + work --once (jobs +1/-1). T1 fault scope = infra only:
   stop cassandra → health NOT READY → start → READY, homepage never 500s.
   Consumer retry/replay belongs to Gate 6 (out of scope).

### T1 session deviations / findings (all documented in `docs/database/nosql-local.md`)

- D1 Cassandra CQL auth OFF locally (stock `cassandra:5.0` has no env-switch for
  `PasswordAuthenticator`; custom cassandra.yaml risks the READY<=120s gate).
  Loopback-bound, dev-only. Revisit if threat model changes.
- D2 No PHP container in this slice (app runs on host PHP 8.4.23, satisfies `^8.3`);
  if added later, pin `php:8.3-*`.
- D3 Pre-existing unmanaged `cassandra:latest` container (port 9042, no caps, 21h up)
  removed for reproducibility; orphan volume `cassandra_data` left untouched
  (compose uses `vietfeed_cassandra-data`).
- D4 Host has NO MySQL server (connection refused; `vietfeed.test` unresolvable) →
  smoke script verifies the same session/cache/queue code paths on temp sqlite and
  labels the mysql leg SKIP; full-path PASS needs a host with MySQL. Homepage 500s
  are this pre-existing mysql-down baseline (identical with cassandra up/down).
- D5 `artisan serve` drops non-allowlisted env in the worker → smoke uses `--no-reload`.
- D6 Tinker closures can't rehydrate in `queue:work` (eval'd source) → smoke generates
  a temp `SmokeProbeJob` (repo job conventions) and removes it on exit.
- D7 Mongo `ping` is auth-exempt → healthcheck uses `connectionStatus.authenticatedUsers`
  (negative-controlled: wrong creds fail).

## Acceptance criteria status

- Gate 1 (reproducible infra): T1 REVIEWABLE — all #1 boxes green except MySQL-path
  full PASS (environment-blocked, D4; code paths proven on sqlite)
- Gate 2 (Cassandra spike or documented fallback): OPEN (T2, not started)

### #1 acceptance detail (2026-09-22 session)

- [x] `docker compose config` validates (plus fixed 2 real bugs found via warnings/tests)
- [x] All 4 healthy together; `./scripts/nosql-health.sh --wait 120` → 4/4 READY
      (cold start: cassandra READY ~80s; warm re-up: 4/4 in ~10s); compose `ps` agrees
- [x] One native smoke query per DB passes (mongo insert w/ auth, cql DESCRIBE,
      neo4j CREATE node, redis SET)
- [~] MySQL smoke script: 7/7 PASS on sqlite fallback; mysql leg SKIP (D4)
- [x] Fault scope: stop cassandra → 3/4 NOT READY (exit 1) → start → 4/4 READY (exit 0)
- [x] Homepage 200 in 0.011s (< 2s, sqlite-backed); 500s only from mysql-down baseline,
      identical with cassandra up or down (never 500s *from cassandra*)
- [x] `php artisan --version` → Laravel 13.11.2; `composer run test` green
- [x] `docker stats` recorded (below); no container at limit; machine responsive

## Test results (exact, 2026-09-22)

- `docker compose config` → valid (6 volumes, 4 services, 127.0.0.1 binds)
- `./scripts/nosql-health.sh --wait 120` → `4/4 READY`, exit 0
- `./scripts/smoke-mysql-path.sh` → `smoke: 7 passed, 0 failed`, exit 0 (mysql SKIP noted)
- `php artisan test tests/Unit/ExampleTest.php` → passed (1 test, 1 assertion)
- `composer run test` → passed, 44 tests, 202 assertions
- `docker stats --no-stream` (steady state, all healthy):
  - vietfeed-mongo     CPU 27.22% | MEM 218.9MiB / 768MiB (28.50%)
  - vietfeed-redis     CPU 1.23%  | MEM 11.13MiB / 256MiB (4.35%)
  - vietfeed-cassandra CPU 4.17%  | MEM 1.301GiB / 1.5GiB (86.77%) — under ceiling, watch in T2
  - vietfeed-neo4j     CPU 1.25%  | MEM 535.3MiB / 1GiB   (52.27%)
- Docker Desktop MemTotal here: ~3.9GiB (not 6GB); ceilings total ~3.5GiB — fits, no pressure observed
