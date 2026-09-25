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

- T1 (client + sessions → Redis): GitHub #13 — MERGED via PR #16 (2026-09-25). Gate 5.1 DONE. Control evidence: gh merge + issue CLOSED verified this session.

## Goal-x trial verdict (2026-09-25) — KEEP for #14/#15

- Auditor vs swarm: consistent (approved vs PASS_WITH_NOTES, notes carried). No conflict.
- STOP rules held unassisted: main untouched at 0cdc2bf, no merge/push, no #14 commits, WIP safe.
- Packet kept canonical (STATUS + REVIEW updated by goal session); goal archived to .pi/goals.
- Empirical win: phpredis retained (ext-redis 6.3.0 host-verified) instead of predis switch.
- Guardrails stay: packet canonical, STOP hard, auditor ON, supervised scope per ticket.
- Evidence status of this edit: prose only. Test numbers below are goal-reported, NOT control-run.
- Control evidence (commands actually run by control this session, results):
  `git diff main...redis/sessions-13 --stat` → 5 files, +256/-4, all in T1 scope;
  `git log main..redis/sessions-13` → 4 stacked commits (6ef55fb, 0a2dceb, 812b53d, b30de04);
  `grep Verdict REVIEW.md` → PASS_WITH_NOTES; packet files present (REVIEW/STATUS/TASK/T1-T3).
  Full test re-run explicitly NOT done by control (branch checkout would risk worktree);
  assigned to the PR-open session on the branch itself.
- T2 (cache + queue → Redis + drain + pre-warm): GitHub #14 — goal run in progress (branch `redis/cache-queue-14`)
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
- `composer run test` — 55/55 pass, 248 assertions, green 3x consecutively at finalize.
  W1: one transient error-code-2 (1 of 4 runs, uncaptured, no repro) — watch-item.
- `vendor/bin/pint --test` on changed PHP files — PASS.
- `./scripts/nosql-health.sh` — 4/4 READY (redis Up healthy).
- No secrets committed (placeholders only; `.env` never read nor written).

## Checkpoint

- Base: 0cdc2bfc258c642be609079d7acf0b69f48ffe8d
- Review SHA: 6ef55fbf2d24908735e1a58a323962ad38f0b8f5
- Fix SHA: 0a2dceb303d5548e1cc4551c13481493ce6f3267 (F1 + final STATUS)
- Verdict: PASS_WITH_NOTES (REVIEW.md; full swarm 3/3 landed: standards P3 fixed,
  spec no P0/P1, runtime owner 6/6 independent PASS with identical numbers)

## T2 startup (2026-09-25 goal run)

- Ponytail full adopted; enables: no slash tool in harness — offline guards apply.
  Warden probe-flag honored (fwrite debug line removed same-turn); standing rule kept:
  diagnosis via tinker/vendor reads, no scratch files.
- `git checkout main` blocked by control's uncommitted STATUS annotations → stashed
  single file, checked out main 6f0da52, branched, popped (annotations preserved).
- Preserved WIP (prior STATUSes, CLAUDE.md, SourceSeeder.php) untouched, uncommitted.
- `docker compose ps` — all 4 Up healthy (no start needed).

## T2 drain procedure (exact commands) + execution

- `php artisan tinker --execute="echo DB::table('jobs')->count();"` → dev MySQL
  Connection refused (no server binary on host: no brew/opt mysql) — zero in-flight
  jobs possible here; suite path unaffected (sqlite). MySQL-host procedure stands:
  stop dispatch → `php artisan queue:work --stop-when-empty` → re-query count=0 → flip.
- Executed here: dispatch test asserts `Queue::size()===1` post-dispatch and `===0`
  post-worker (redis queue drains to zero) + `failed_jobs` count 0.

## T2 files changed

- `.env.example` — QUEUE_CONNECTION database→redis (+drain/failed_jobs comment),
  CACHE_STORE database→redis (+pre-warm/.bak/RateLimiter comment). Placeholders only.
- `tests/Feature/RedisQueueTest.php` (new, in-suite) — real dispatch (no Queue::fake)
  + ticker pre-warm, DB15 isolation, flushdb tearDown.
- `.agent/tasks/redis-cutover/STATUS.md` — this file.
- Out-of-scope touched: NONE. TickerService/.bak untouched; schedule lines unchanged;
  `composer dev` queue:listen line unchanged (driver-agnostic; flip flows via .env).
  failed_jobs stays database-uuids (MySQL) — documented in .env.example.

## T2 decisions

- Queue redis uses `default` conn (DB 0, shared with sessions — matches scout blast
  model); cache redis uses `cache` conn (DB 1). Tests isolate both on DB 15.
- Real dispatch runs the worker IN-PROCESS (`queue:work --once`, redis) so Http::fake
  applies — proves dispatch→run→side-effects without external network.
- Ticker pre-warm = `TickerService::fetch()` once post-flip; `.bak` fallback untouched.
  Note: `redis-cli KEYS 'ticker.*'` misses Laravel's key prefix — use `*ticker*`.
- Root-caused test failures (app code correct throughout): SJC payload uses
  `buy`/`sell` (not `value`); test-client sends no cookies (T1 syncCookies lesson
  re-applied via real controller flow — dispatch path needs no jar).

## T2 acceptance criteria status

- Drain documented + executed: DONE (procedure above; redis queue 1→0 asserted).
- Real dispatch green: DONE (FetchSourceFeedJob on redis worker, log success +
  items_found 2, failed_jobs 0).
- Ticker warm post-flip: DONE (usd/sjc/.bak keys on redis, `*ticker*` present).
- Suite green; pint clean: DONE (57/57, pint file PASS).

## T2 test results

- `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` — 2/2 pass, 11 assertions.
- `php artisan test --filter=SourceHealthMonitor` — 3/3 pass, 9 assertions.
- `composer run test` — 57/57 pass, 259 assertions (55 prior + 2 new).
- `vendor/bin/pint --test tests/Feature/RedisQueueTest.php` — PASS.
- `./scripts/nosql-health.sh` — 4/4 READY. No secrets (`.env` never read/written).

## T2 checkpoint

- Base: 6f0da5226e88b85f04f251ea9bb9d57c3577d2ab
- Review SHA: 3783640fb186e4de9720ad8980bb51a7c3f32254
- Fix SHA: (recorded on fix commit below)
- Verdict: PASS_WITH_NOTES (REVIEW.md; full swarm 3/3: standards 3xP3 fixed,
  spec code PASS all criteria, runtime owner 6/6 with identical numbers)
