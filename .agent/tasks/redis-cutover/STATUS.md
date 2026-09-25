# STATUS — redis-cutover

- Branch: `redis/sessions-13` (from main 0cdc2bf, 2026-09-25)
- Base commit: 0cdc2bfc258c642be609079d7acf0b69f48ffe8d (Merge PR #12, Gate 4 DONE)
- Classification: Large (session driver cutover touches auth; flush test is destructive by design)
- Control session: pi-control
- Grill: SKIPPED (Gate 5 executable as-is)

## Scouts

- Repo scout: done — phpredis default but no client installed; DB index collision found; 1 job, 1 dispatch site
- Test scout: done — suite never touches drivers; actingAs break-risk; real dispatch untested
- Risk scout: done — logout/drain/stampede/FLUSHDB mitigations assigned per ticket

## Tickets

- T1 (client + sessions → Redis): GitHub #13 — goal-x TRIAL run pending (packet canonical; goal mirrors ticket; no code changed by this edit)
- T2 (cache + queue → Redis + drain + pre-warm): GitHub #14 — blocked by #13
- T3 (streams + retry/DL + trending + flush/rebuild): GitHub #15 — blocked by #13

## Startup (2026-09-25 goal run)

- Ponytail full adopted (ladder enforced, smallest diff). Typesafe/warden slash enables:
  no slash tool in this harness — offline guards apply (no secrets, no destructive cmds).
- Warden live flags honored same-turn: (W1) reverted speculative `config/nosql.php`
  redis_nosql default edit — zero callers route through that stub (verified by grep),
  so the ticket-named DB separation lands in `.env.example` only (single point).
  (W2/W3) scratch debug files removed immediately; standing rule: no more scratch files.
- `git checkout -b redis/sessions-13` from updated main 0cdc2bf (worktree tracked
  modifications from prior slices left untouched, never to be committed here).
- Preserved WIP: prior-slice STATUS.md edits + CLAUDE.md + SourceSeeder.php stay
  uncommitted (owner's). Stash from neo4j run already consumed there; nothing stashed now.
- `docker compose ps` — all 4 Up (healthy), incl. vietfeed-redis (no start needed).
- Client decision (ticket rule): ext-redis 6.3.0 verified on host PHP (`php -m` +
  `phpversion('redis')`) → phpredis stays (already config default), no new package,
  pinned via `"ext-redis": "^6.0"` platform requirement (lock hash synced, validate clean).

## Files changed

- `composer.json` / `composer.lock` — `"ext-redis": "^6.0"` pin (hash-only lock diff).
- `.env.example` — SESSION_DRIVER database→redis (+cutover comment),
  SESSION_CONNECTION=default (+comment: null would ride cache conn DB1),
  REDIS_NOSQL_DB 1→2 (+comment: cache DB1, sessions DB0, never share).
  Placeholders only, no secrets.
- `tests/Feature/RedisSessionTest.php` (new, in-suite) — 4 live-redis tests, DB15
  isolation + flushdb tearDown (never touches dev DB0/1).
- `.agent/tasks/redis-cutover/STATUS.md` — this file (checkpoint tracking).
- Out-of-scope touched: NONE. `config/nosql.php` stub deliberately UNTOUCHED (W1).
  ext-mongodb platform failure in `check-platform-reqs` is pre-existing, unrelated.
- Preserved, NOT committed: prior-slice STATUS.md x3, CLAUDE.md, SourceSeeder.php.

## Decisions (2026-09-25, scout-driven)

- Redis client: T1 resolves phpredis-vs-predis (no ext in composer.json); pure-PHP predis preferred
  unless ext-redis verified on host — no C-extension builds for a driver.
- DB indexes separated before flip (REDIS_NOSQL_DB collision fixed in T1).
- Evidence status of this edit: packet prose only, zero code changed, zero tests applicable.
  Scout outputs live in subagent artifacts; ticket acceptance defines the future evidence.
- Queue drained before driver flip (T2 procedure). Ticker pre-warmed (T2). FLUSHDB on
  selected DBs only, never FLUSHALL (T3). Re-login after flush is acceptable per spec §9.4.

## Acceptance criteria status (T1)

- Client installed + pinned, round-trip proven: DONE (ext-redis 6.3.0 host-verified,
  `^6.0` pin, phpredis default; login test round-trips session data via live Redis).
- Login/logout/remember-me green on redis: DONE (4/4 live-redis feature tests).
- Cutover logout acknowledged by design: DONE (documented .env.example + test 3 +
  below; old DB sessions unreadable once driver flips; recaller survives).
- Suite green; pint clean; no secrets: DONE (55/55, pint file PASS, placeholders only).

## Session routing decision (read this before T2)

- Laravel session-redis rides the CACHE store → `session.connection` selects the redis
  connection (NOT the `default` one by magic). Null connection = cache conn (DB 1).
- T1 pins `SESSION_CONNECTION=default` (DB 0): sessions DB0, cache DB1, nosql DB2.
  Matches risk-scout blast model (sessions DB0 + cache DB1 + queued jobs).
- Test-client caveat (found by failing asserts, root-caused via framework source):
  Laravel's test client persists NO response cookies — every request here re-syncs
  the jar (syncCookies helper) to behave like a browser. Without that, logout
  destroys a fresh empty session and orphans the real one (app code was correct).
- Live-vs-fake split: whole suite uses array/session/sync fakes per phpunit.xml EXCEPT
  `tests/Feature/RedisSessionTest.php` (live redis, DB15). `php artisan test
  --filter=Auth` covers existing auth surface (still array-driver); live proof lives
  in RedisSessionTest.

## Test results

- `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` — 4/4 pass, 22 assertions.
- `php artisan test --filter=Auth` — 21/21 pass, 57 assertions.
- `composer run test` — 55/55 pass, 248 assertions (51 prior + 4 new).
- `vendor/bin/pint --test` on changed PHP files — PASS.
- `./scripts/nosql-health.sh` — 4/4 READY (redis Up healthy).
- No secrets committed (placeholders only; `.env` never read nor written).

## Checkpoint

- Base: 0cdc2bfc258c642be609079d7acf0b69f48ffe8d
- Review SHA: (recorded on checkpoint commit below)
