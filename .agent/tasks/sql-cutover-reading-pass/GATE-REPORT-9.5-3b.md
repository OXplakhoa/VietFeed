# Gate 9.5 slice 3b — Reading Pass on Redis atomic counters + durable history

## Implementation

### Plan

Branch `cutover/reading-pass` at `ea30e3a`. Before any edit the owner WIP hash check passed and `composer run test` passed with
368 tests / 6,831 assertions on the isolated stores (exit 0).

**Durable store (outcome 1).** Two durable layers, each with one job:

- MongoDB `article_unlocks` is the *current-state ledger*: one document per (subject, article). It answers "already unlocked"
  (a point lookup on a unique index), is the source every Redis counter is rebuilt from, and is the only place guest→user transfer
  and guest cleanup mutate. Cassandra is not used for this because spec §9.2 forbids Cassandra as generic CRUD for current state,
  and transfer/cleanup are updates/deletes of current state; MongoDB owns current state (§9.1). This is an addition to the feature
  map row ("Plan in MongoDB subscription, Redis quota, Cassandra history"), not a change: the plan/entitlement still comes from the
  Mongo subscription, Redis stays the atomic quota and Cassandra the history; MongoDB is what makes Redis rebuildable (§9.4
  "Redis must not be the only durable holder").
- Cassandra history (spec §9.2 "reading/unlock history by user and month", feature map `reading_history_by_user_month`):
  `unlocks_by_user_month` partition `(user_id, year_month)` and `unlocks_by_session_day` partition `(session_hash, unlock_day)`,
  clustering `unlocked_at DESC, unlock_id ASC`, UTC buckets as in slice 3a. Guest rows are written `USING TTL` (30 days from the
  unlock time). Written asynchronously through a dedicated Redis Stream + the shared `StreamConsumer` (retry, DLQ, replay), with
  `reemit` from the Mongo ledger, exactly like slice 3a. No profile history view exists, so the history query is an admin/demo CLI
  (`reading-pass history --user= --month=`); adding a UI would be a product change.

Document: `_id` UUIDv7 string, `subject` (`u:<user_id>` or `s:<sha256(session id)>`), `user_id` (string|null), `session_hash`
(string|null), `article_id`, `state` (`pending`|`granted`), `unlocked_at` (BSON date), `tier`, `origin`
(`unlock`|`transfer`|`import`|`seed`), optional `fallback: true`, optional `legacy_sql_id`. Raw session ids are no longer persisted
(SQL stored them); only a SHA-256 is kept, which is enough to find a session's unlocks at login. Indexes (new migration): unique
`{subject, article_id}`; `{subject, unlocked_at}` (window count/rebuild); partial `{subject, fallback}`; `{state, unlocked_at}`
(dashboard, cleanup); `{user_id}` and `{article_id}` (admin counts).

**Redis keys and Lua (outcome 2).** All on the `nosql` connection (physical prefix applied by phpredis):

- `reading-pass:v1:gen` — string generation token; changing it invalidates every window at once (import, operator rebuild).
- `reading-pass:v1:<subject>:w` — one sorted set per subject. Members `g:<article>` (granted) and `p:<article>` (reservation
  loaded by a rebuild), score = unlock time in ms; sentinel member `~ready:<gen>` at score −1. One set serves both window kinds:
  the caller passes the window start (now−5 h for verified, Vietnam 00:00 for unverified/guest), so a mid-day tier change is
  answered from the same data. Members older than now−25 h are trimmed on every decision; `EXPIRE` 26 h on every grant/load.
- `reading-pass:v1:<subject>:lock` — rebuild/transfer lock, owner token, `NX EX 10`.
- Stream `reading-pass:events:v1`, group `reading-pass-history-v1`, DLQ `reading-pass:dead:history:v1`, errors hash
  `reading-pass:errors:history:v1`.

`DECIDE` (KEYS window, gen; ARGV article, now_ms, window_start_ms, limit or −1, trim_cutoff_ms, stale_cutoff_ms, ttl): if the
sentinel for the current gen is missing → `{-1}` (rebuild needed). Trim old members. If `g:<article>` exists → `{2, used, oldest}`
(already, no consume). Remove the caller's own `p:<article>`. If any other `p:` member is older than 60 s → `{-2, members}`
(PHP resolves them from MongoDB). `used = ZCOUNT [window_start, +inf]`; if `limit ≥ 0 and used ≥ limit` → `{0, used, oldest}`;
else `ZADD now g:<article>`, `EXPIRE` → `{1, used+1, oldest}`. `oldest` is the first score in the window (for "Lượt kế tiếp sau …").
Because the check and the `ZADD` are one Lua call, concurrent requests are serialized by Redis and can never exceed the limit.
Other scripts: `LOAD` (lock-owner and gen checked, `DEL` + sentinel + members + `EXPIRE`), `RESOLVE` (convert/remove stale
reservations), `STATE` (read-only used/oldest for navbar/profile/admin), `RELEASE` (owner-checked lock delete).

**Request protocol** (metered tiers and Pro; Pro passes limit −1 so its unlocks are still recorded and counted if Pro lapses;
admin returns before any store access, as today):

1. One Mongo query `{subject, $or: [{article_id}, {fallback: true}]}`. A granted doc for the article → allowed, no consume.
   A pending doc for the article (concurrent same-article request) → short backoff and retry (bounded).
2. Reserve: insert `{state: pending, unlocked_at: now}` (unique index; duplicate → retry from 1).
3. If the subject has `fallback` docs (granted while Redis was down) → rebuild before deciding.
4. `DECIDE`. `-1` → rebuild under the lock, then decide again. `-2` → resolve stale reservations from MongoDB, decide again.
   `1`/`2` → `updateOne(pending→granted)` (re-insert if a sweeper removed it), publish history event, allowed.
   `0` → delete the reservation, locked.

**Eviction/flush safety (outcome 3) and races.** Rebuild = acquire lock (wait ≤ 1 s) → read gen → read Mongo docs of the subject
(granted within 25 h → `g:`, pending younger than 60 s → `p:`, plus every `fallback` doc id) → `LOAD` (aborts if the lock owner or
gen changed) → unset the observed `fallback` flags → release.

- *Grant in flight while the key is evicted.* The reservation is in MongoDB before `DECIDE`, so a rebuild that runs between a
  grant and its `pending→granted` update loads it as `p:` and counts it: no extra unlock is ever granted. The owner's later
  decisions treat its own `p:` as not-yet-counted, so it is not double counted either.
- *Request decides while key missing.* `DECIDE` refuses (`-1`) until a rebuild publishes the sentinel; nothing is decided on a
  partial set. A reservation inserted after the rebuild's Mongo read decides after the load, against the loaded count.
- *Denied reservation loaded as `p:` just before its delete.* Over-counts by one for ≤ 60 s, then the `-2` path resolves it from
  MongoDB (doc absent → removed). Same for a crash between reserve and decide (abandoned pending doc → removed after 60 s, doc
  swept by cleanup). Never over-grants; may under-grant one slot for ≤ 60 s only when an eviction coincides with a denial.
- *Granted but crash before `pending→granted`.* Redis has `g:`; MongoDB keeps a pending doc. If the key is later rebuilt after
  60 s the doc is treated as abandoned (slot returned, user must unlock the article again). Documented limitation (crash case).
- *Concurrent rebuilds.* The lock admits one; the others wait and then decide on the published set. `LOAD` checks the owner token,
  so a rebuild whose lock expired cannot overwrite a newer one. A gen change during a rebuild aborts the load.
- *Lock key evicted while held:* harmless for rebuilds (LOAD re-checks the owner token); the residual is the transfer lock
  expiring mid-transfer (corrected wording, fix round R6).

**Redis down (outcome 3).** Choice: **fail-open with the durable record**. The same reservation is decided by a MongoDB count of
the subject's window (granted + fresh pending, excluding itself); since every request reserves before counting, a pure
fallback decision never over-grants (fix-round correction: the only over-grant is the mixed Redis/fallback case, ≤ 1 per
in-flight fallback grant). The doc is marked `fallback: true`; the next decision for that
subject with Redis available rebuilds the window first, so a stale pre-outage counter never over-grants afterwards. Reads
(navbar/profile/admin) fall back to the same Mongo count. Rationale: Redis is a rebuildable projection (§9.4, §10.4); fail-closed
would lock out verified and Pro readers for an infrastructure fault and would need new user-facing copy (out of scope).

**Guest transfer (outcome 4).** Under the user's lock: `DEL` user window → for each guest granted doc insert a user doc
(new UUIDv7, same article, original `unlocked_at`, `origin: transfer`); duplicate key ⇒ the user already had it (dropped) → delete
the guest doc → `DEL` user and guest windows → release. A concurrent user request either decides on a missing key (`-1` → waits
for the lock → rebuilds from the post-transfer ledger) or, if it ran earlier, against the pre-transfer state. If Redis is down,
the moved docs are marked `fallback: true`. History event per moved unlock (user partition; the session partition keeps its rows).

**Cleanup.** `unlocks:cleanup` deletes guest docs older than 30 days and pending docs older than 1 hour; Redis windows expire by
TTL; Cassandra guest rows by TTL.

**Import (outcome 5).** `unlocks:import-sql {--emit} {--force}`: reads SQL `article_unlocks` in id order, chunks of 500, natural
key (subject, article), `legacy_sql_id`, original `unlocked_at`, `state: granted`, `origin: import`; never overwrites an existing
document; refuses a second run without `--force` (any doc with `legacy_sql_id`); `--emit` publishes backfill history events;
finishes by changing the generation token (all windows rebuild lazily from MongoDB). The SQL table is left in place and unused.

**Files.** New: `app/ReadingPass/{ReadingPassQuota,UnlockLedger,ReadingPassHistory,ReadingPassSqlImport}.php`,
`app/Console/Commands/{ReadingPass,ImportSqlUnlocks}.php`, Mongo index migration, `tests/Feature/ReadingPassNosqlTest.php`.
Changed: `app/Services/ReadingPassService.php` (same public API), `app/Models/{ArticleUnlock,User,Article}.php`,
`app/Console/Commands/CleanupOldGuestUnlocks.php`, `app/Http/Controllers/Admin/{Dashboard,User,Article}Controller.php`,
`routes/console.php` (scheduled consumer), `tests/TestCase.php`, `tests/Feature/ReadingPassTest.php`, `docs/database/nosql-local.md`.

**Test plan.** Deterministic clock (`Carbon::setTestNow`/`travelTo`). Every tier and window (guest/unverified Vietnam day across
midnight, verified rolling 5 h with next-unlock label, Pro unlimited + recorded, admin no record), already-unlocked never consumes
(also outside the window and after eviction), real concurrency: N PHP worker processes released together against one user at
limit−k (exactly k grants), key eviction/flush → rebuild → identical decision and label, in-flight reservation counted by a
rebuild, stale reservation resolution, gen change, Redis down (fail-open, fallback flag, rebuild after recovery), guest transfer on
login/register/Google (dedupe), cleanup, import idempotency/refusal/`--force`/`--emit`, Cassandra history + DLQ replay + reemit,
admin counts, and the grep proof.

### Decision log

| # | Decision | Why |
|---|---|---|
| D1 | MongoDB `article_unlocks` ledger + Cassandra history (both) | "Already unlocked", transfer and cleanup are current-state reads/updates/deletes (MongoDB, §9.1); Cassandra is query-first history and not CRUD (§9.2); Redis must be rebuildable from a durable holder (§9.4). The plan/entitlement still comes from the Mongo subscription (feature map row unchanged in substance). |
| D2 | One sorted set per subject, window start passed by the caller | One structure answers both the rolling 5 h and the Vietnam-day windows, and stays correct when a user's tier changes mid-day (e.g. verifies email, Pro lapses). |
| D3 | Sentinel member `~ready:<gen>` inside the set (not a separate meta key) | Eviction removes the set and its sentinel together, so a partial set can never be trusted; a generation token invalidates all windows in O(1) (import, operator, tests). |
| D4 | Reserve in MongoDB (`pending`) before the Redis decision | Closes the "grant in flight while the window is evicted" race: a rebuild always sees in-flight grants. Cost: one extra Mongo write per new unlock. |
| D5 | Stale `p:` (> 60 s) resolved from MongoDB, never dropped blindly | Dropping could free the slot of a grant whose `pending→granted` update lagged; resolving from the ledger is always correct. |
| D6 | Rebuild drops the window before reading the ledger, under an owner-checked lock | No decision can be granted between the ledger read and the publish, so a rebuild never erases a concurrent grant. |
| D7 | Redis down → fail-open on the ledger, `fallback: true` marker, rebuild on next decision | Redis is a projection (§9.4, §10.4); fail-closed would lock out paying/verified readers for an infra fault and need new copy (out of scope). Pure fallback never over-grants (reserve-then-count); the only over-grant is the mixed Redis/fallback case, ≤ 1 per in-flight fallback grant (corrected in the fix round). |
| D8 | Session ids stored as SHA-256 only (`session_hash`) | A raw session id is a bearer secret; the hash still finds a session's unlocks at login. No behaviour change. |
| D9 | History through a dedicated Redis Stream + shared `StreamConsumer`; event id = ledger `_id` | Async projection (§10.2), retries/DLQ/replay reused; `reemit` is byte-identical so replays are idempotent. Guest rows carry a TTL equal to the remaining 30-day retention. |
| D10 | No profile history UI; `reading-pass history` CLI is the admin/demo query | No history view exists; adding one is a product change. |
| D11 | Admin counts count `state: granted` only | A reservation is not an unlock; it is transient (ms) or abandoned (swept). |
| D12 | Keyspace names follow the module pattern (`reading-pass:v1:*`), not the feature map's illustrative `quota:reading-pass:*` | Same convention as `moderation:queue:v1`, `interactions:trending:v1`. |

### Race analysis (summary; details in the plan)

| Interleaving | Outcome |
|---|---|
| N requests for different articles at the limit | One Lua call each, serialized by Redis: exactly `limit − used` granted (tested with real worker processes, cold and warm window). |
| Same subject + same article concurrently | Unique `{subject, article_id}`: the loser sees the winner's reservation and waits/retries (≤ 4 attempts, 25–100 ms), then reads the settled state. |
| Window evicted between a Redis grant and `pending→granted` | Rebuild loads the reservation as `p:` and counts it (tested). |
| Rebuild races decisions | Window dropped first; DECIDE answers `-1` until LOAD; losers wait for the lock then decide on the published set (tested cold-window race). |
| Two rebuilds | Lock admits one; LOAD checks owner token and generation. |
| Denied reservation loaded as `p:` just before its delete | Counted ≤ 60 s, then resolved (doc absent → removed). Under-grant only, only with a coinciding eviction. |
| Crash after reserve | `p:` counted ≤ 60 s then resolved; doc swept by `unlocks:cleanup` after 1 h (tested). |
| Crash after Redis grant, before `granted` | Doc stays pending; a later rebuild after 60 s treats it as abandoned (slot returned, user re-unlocks). Documented. |
| Transfer vs a user's concurrent decision | Transfer holds the user lock and drops the window; decisions wait for a rebuild that reads the post-transfer ledger. |
| Fallback grant while another process still uses Redis | The flagged doc forces a rebuild on the subject's next lookup (a failed forced rebuild now counts the ledger); requests already past lookup may decide on the stale window: ≤ 1 extra unlock per in-flight fallback grant. |
| Rebuild lock evicted while held | LOAD re-checks owner and generation, so no stale publish. Residual is the transfer lock expiring mid-transfer (partial window until the transfer's final drop). |

### Test edits

| File | Edit and reason |
|---|---|
| `tests/TestCase.php` | Teardown also deletes the `article_unlocks` MongoDB collection (fixtures moved from SQLite rollback to Mongo) and, after a live DB15 check, sets a new Reading Pass generation and deletes the history stream keys: windows live in Redis DB15 and fixed guest session ids recur across tests and suite runs. Storage-only. |
| `tests/Feature/ReadingPassTest.php` | `assertDatabaseHas('article_unlocks', …)` now names the `mongodb` connection (the model moved stores). Assertions unchanged. |
| `tests/Feature/CommentsMongoTest.php` | Its listener sanity check relied on Reading Pass SQL queries (the only SQL left on those pages); it now issues an explicit `select 1` probe before `assertNotEmpty`. The `comments` assertion is unchanged. |
| `tests/Feature/ReadingPassNosqlTest.php` (new, 14 tests) | Day windows across Vietnam midnight (guest + unverified), verified rolling window boundary and labels, Pro recorded/admin unrecorded/admin counts, eviction/flush/generation → same decision and label, in-flight grant counted + stale reservation resolution + abandoned reservation, real multi-process race (cold and warm window), Redis down fail-open + rebuild after recovery, transfer on login/register/Google with dedupe, transfer with Redis down, cleanup (+ swept-reservation re-insert), import idempotency/refusal/`--force`/`--emit`/generation, Cassandra history + reemit idempotency + DLQ/inspect/replay + history command, grep + runtime SQL proof, index rerun and BSON types. |

`ProSubscriptionTest` and `CatalogArticlePagesTest` (including its `ArticleUnlock::create` fixture and query-count tests) pass unchanged.

Mutation checks (each reverted, `cmp` verified): Lua `>=`→`>` fails 7 tests; rebuild ignoring `pending` fails the in-flight test;
a non-atomic state-then-decide service fails the warm-window race (5 grants instead of 1).

### Files changed

- New: `app/ReadingPass/{ReadingPassQuota,UnlockLedger,ReadingPassHistory,ReadingPassSqlImport}.php`,
  `app/Console/Commands/{ReadingPass,ImportSqlUnlocks}.php`,
  `database/migrations/2026_11_18_220001_create_mongodb_article_unlock_indexes.php`, `tests/Feature/ReadingPassNosqlTest.php`.
- Changed: `app/Services/ReadingPassService.php` (public API unchanged), `app/Models/{ArticleUnlock,User,Article}.php`,
  `app/Console/Commands/CleanupOldGuestUnlocks.php`, `app/Http/Controllers/Admin/{Dashboard,User,Article}Controller.php`,
  `routes/console.php` (scheduled `reading-pass consume`), `tests/TestCase.php`, `tests/Feature/{ReadingPassTest,CommentsMongoTest}.php`,
  `docs/database/nosql-local.md`, this report.
- Unchanged callers: `ArticleController::show`, `ProfileController::readingPass`, the three auth controllers, `AppLayout`,
  `DemoEngagementSeeder` (writes the ledger through the model's `creating` normalisation), all views and copy.

### Verification

Sequential runs on MongoDB `vietfeed_test`, Redis DB15 (live `getDbNum()` guard), Cassandra `vietfeed_test`, SQLite `:memory:`.
Logs in the session scratchpad (`/tmp/claude-1000/-home-dolakhoa/a066e9b5-c09b-4825-8951-e54303a5e6ee/scratchpad/`). Each command
redirected both streams and recorded `$?`.

| Command | Exit | Result |
|---|---:|---|
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` (before) | 0 | 6/6 OK |
| `composer run test` baseline (main `ea30e3a`) | 0 | 368 tests, 6,831 assertions (`baseline.log`) |
| `vendor/bin/phpunit tests/Feature/ReadingPassTest.php tests/Feature/ProSubscriptionTest.php` after the core move | 0 | 17 tests, 282 assertions (`s1.log`) |
| `composer run test` first full run after the move | 1 | 367/368: `CommentsMongoTest` listener sanity relied on Reading Pass SQL; fixed with an explicit probe (`full1.log`) |
| `vendor/bin/phpunit tests/Feature/ReadingPassNosqlTest.php` | 0 | 14 tests, 466 assertions (`t1.log`) |
| Mutations m1/m2/m3 (reverted) | 1, 1, 1 | Tests detect each defect (`m1.log`, `m2.log`, `m3.log`) |
| `composer run test` after new tests | 0 | 382 tests, 7,672 assertions (`full2.log`) |
| `vendor/bin/phpunit tests/Feature/ReadingPassNosqlTest.php tests/Feature/ReadingPassTest.php tests/Feature/CommentsMongoTest.php` ×3 | 0, 0, 0 | 35 tests, 912 assertions each (`changed-{1,2,3}.log`) |
| `composer run test` ×3 (final) | 0, 0, 0 | **382 tests, 7,674 assertions** each (`final-full-{1,2,3}.log`) |
| `composer check-platform-reqs` | 0 | all satisfied (`platform.log`) |
| `xargs -r vendor/bin/pint < touched-php.txt`; `xargs -r vendor/bin/pint --test < touched-php.txt` | 0, 0 | 20 touched PHP paths only; admin controller diffs verified import-only beyond the intended lines |
| `rg -n -i -f sql-reader.regex app routes resources database/seeders` | 0 | single hit `app/ReadingPass/ReadingPassSqlImport.php:25` (`sql-proof.log`); runtime proof in `test_only_the_import_reads_sql_article_unlocks` |
| `git diff --check` | 0 | clean |
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` (after) | 0 | 6/6 OK, unchanged |
| `git status --short` | 0 | prior owner entries preserved; new entries are exactly the files listed above (`status-after.log`) |

SQL scan pattern:

```regex
(table[[:space:]]*\([[:space:]]*['"]|exists:|from[[:space:]]+["`]?|join[[:space:]]+["`]?|into[[:space:]]+["`]?|update[[:space:]]+["`]?)\barticle_unlocks\b
```

No git staging/commit/stash/checkout, no container lifecycle operations, no `.env`/compose edits, no dev-store access, no
migrate/seed against dev. Owner WIP untouched.

### Cutover order

1. Deploy; `php artisan migrate --force` (new MongoDB indexes only); `php artisan reading-pass schema` (Cassandra tables).
2. Fresh development path (spec §13 item 2): seed, then `php artisan reading-pass reemit` for history.
   Legacy path: read-only SQL backup, pause traffic, `php artisan unlocks:import-sql --emit`, review totals
   (`inserted/existing/skipped/published/publish_failed`); on publish failures run `reading-pass reemit` after Redis recovers.
3. Resume traffic and the scheduler (`reading-pass consume` every 5 s, `unlocks:cleanup` daily); check `reading-pass inspect`.
4. SQL `article_unlocks` stays in place, unused, until the SQL-removal slice.

### Known limitations

- Crash after the Redis grant but before `pending→granted`: if the window is rebuilt more than 60 s later, the slot is returned
  and the user must unlock that article again (never an over-grant).
- Transfer lock expiring/evicted mid-transfer: a rebuild may load a partial ledger until the transfer's final window drop
  (rebuild locks themselves are safe: LOAD re-checks owner and generation).
- Mixed Redis/fallback operation: ≤ 1 extra unlock per in-flight fallback grant; pure fallback never over-grants. After recovery
  the displayed allowance of a subject can lag until its next unlock decision rebuilds the window (decisions rebuild first).
- Two Mongo writes + one Lua call per new unlock (reserve, decide, mark granted); a repeat visit is one Mongo query + one Lua read.
- Same-article concurrent requests from one subject: see the fix round (bounded settle, never locked while room remains).
- A guest request granted after the transfer started stays under the old guest subject (legacy behaviour).
- Admin user list computes allowance per listed user (20 Redis reads, Mongo counts for users without a built window), as before.
- History buckets are UTC months/days (as slice 3a), not Vietnam calendar months.
- The legacy import and fresh seeding were rehearsed only on the isolated stores.

### Open questions for control

1. Accept fail-open-on-ledger as the Redis-down behaviour (D7), or prefer fail-closed with new copy?
2. Accept storing only the SHA-256 of guest session ids (D8)?

**STATUS: READY_FOR_REVIEW**

## Fix round (REVIEW-1)

Control accepted D7 (fail-open on the Mongo ledger, no new copy) and D8 (SHA-256 of guest session ids only). REVIEW-1 returned
PASS_WITH_NOTES; every item applied on the same branch, same hard-safety rules.

| Item | Change | Evidence |
|---|---|---|
| R1 (1) | The losing same-article request now re-checks every 25 ms until the winner's reservation is granted (→ already unlocked, nothing consumed) or released (→ reserves and decides normally). The bound is rebuild lock wait 1 s + one Redis timeout + 0.5 s. The `nosql` connection sets no timeout, so the Redis share is 2 s (total 3.5 s); `nosql.reading_pass_settle_ms` overrides it. **Choice when the bound runs out:** treat it as already unlocked when the window has room *excluding* the winner's reservation. Show the article and write no second record. Allowance counts the winner's unlock once. Otherwise show the lock page. **Why:** the winner's reservation is already the record for this (subject, article). With room left, the winner's own atomic decision will grant it, so a second reservation (impossible under the unique index) or a second consumption would double count. | `test_same_article_loser_settles_on_a_slow_winner_and_never_locks_with_room_left`: the winner runs in a separate PHP worker that grants or releases after 0.8 s. The loser waits more than 700 ms and gets access in both cases, writing one record. Bound exhaustion: room left → shown, with no extra record and `used` 14. Full → locked. A mutant with the old 150 ms wait fails. |
| R2 (2) | `decide()`: if the subject has `fallback` documents and the forced rebuild fails (lock busy, Redis error), it throws into the MongoDB-count path. It never runs DECIDE on the stale window. | `test_failed_forced_rebuild_counts_the_ledger_instead_of_a_stale_window`: a foreign lock holder blocks the rebuild. The stale 14-window would grant, but the ledger count (15) denies and the flag is kept. Once the lock is free, the rebuild clears the flag. A mutant that ignores the failure fails the test. |
| R3 (3) | Outage wording corrected in `docs/database/nosql-local.md`, plus the plan, D7, the race table and the limitations above. Every request reserves before counting, so a pure fallback decision never over-grants. The only over-grant is the mixed Redis/fallback case, bounded by one per in-flight fallback grant. | docs + report |
| R4 (4) | DECIDE/STATE/RESOLVE treat a missing `reading-pass:v1:gen` key as "not built". LOAD requires a present, equal generation. `generation()` creates a fresh UUIDv7 with `SET NX` and refuses an empty value, so no `~ready:` (empty-generation) window is ever built or trusted. | `test_missing_generation_key_means_not_built_and_imported_unlocks_are_counted`: the first rebuild creates the generation. After the import, the generation key is evicted. Allowance comes from MongoDB (2), and the next decision rebuilds under a new generation and counts the imported unlock (3, `g:gen-legacy`). A planted `~ready:` window is ignored. The mutant `or ''` fails the test. |
| R5 (5) | Transfer drops the user and guest windows in `finally` whenever Redis is reachable, even when another holder had the lock. A foreign lock is never released. Moved documents are still flagged `fallback` when the lock was not held. | `test_contended_transfer_still_drops_the_user_window`: with a foreign lock held, the window is gone right after the transfer. `allowance()` returns 2 (moved unlock included), the doc is flagged, and the foreign lock is intact. A mutant that drops the window only for the lock owner fails the test. |
| R6 (6) | Residual wording: rebuild locks are safe because LOAD re-checks owner and generation. The real residual is the transfer lock expiring mid-transfer: a partial window lasts until the transfer's final drop. | docs + report |
| 7–8 | Listed as known limitations. A guest request granted after the transfer started stays under the old guest subject (legacy behaviour). The admin user list makes one quota read per listed user (legacy: one SQL count per user). | docs + report |

Round files: `app/Services/ReadingPassService.php`, `app/ReadingPass/ReadingPassQuota.php`, `tests/Feature/ReadingPassNosqlTest.php`
(4 tests plus a single-worker helper), `docs/database/nosql-local.md`, this report.

### Fix-round verification

| Command | Exit | Result |
|---|---:|---|
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` before | 0 | 6/6 OK |
| `vendor/bin/phpunit tests/Feature/ReadingPassNosqlTest.php` first run | 1 | 17/18 passed. A test arithmetic error: the slow-winner user had already reached its limit before the `release` case. Seeded 13 instead of 14 (`r1-t1.log`) |
| same, after the fix | 0 | 18 tests, 558 assertions (`r1-t2.log`) |
| Mutants R1/R2/R4/R5 (each reverted, `cmp` verified) | 1, 1, 1, 1 | each new test detects the old behaviour |
| `vendor/bin/pint` on round files; `vendor/bin/pint --test` on all 20 touched PHP files | 0, 0 | passed |
| `vendor/bin/phpunit tests/Feature/ReadingPassNosqlTest.php tests/Feature/ReadingPassTest.php tests/Feature/CommentsMongoTest.php` ×3 | 0, 0, 0 | 39 tests, 1,002 assertions each (`r1-changed-{1,2,3}.log`) |
| `composer run test` | 0 | **386 tests, 7,764 assertions** (`r1-full.log`) |
| `git diff --check` | 0 | clean |
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` after | 0 | 6/6 OK, unchanged |
| `git status --short` compared with the round-0 status | 0 | identical entry set (no new paths) |

**STATUS: READY_FOR_REVIEW**

## Control acceptance (2026-11-18, Claude Opus 5.5 control)

Workflow: implementation Claude Opus (hard slice: atomic quota without transactions, eviction safety); independent reviews Claude Opus —
REVIEW-1.md PASS_WITH_NOTES (five MINORs fixed), REVIEW-2.md PASS_WITH_NOTES (remaining MINOR/NIT documented by control). Control
decisions: Redis down → fail-open on the Mongo ledger (spec story 30, no new copy); only SHA-256 of guest session ids stored.

| Check | Result |
|---|---|
| `composer run test` (control, after fix round) | exit 0 — 386 tests / 7764 assertions (main ea30e3a: 368 / 6831) |
| `pint --test` touched PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| SQL reads of article_unlocks | only `App\ReadingPass\ReadingPassSqlImport`; `ArticleUnlock` is now the Mongo ledger model |
| Owner WIP sha256 | unchanged |

Browser (GPT 6.1 Terra, before the fix round; isolated Mongo/Redis DB15/Cassandra `vietfeed_test`): guest 5 then locked, reopen free;
login transfers 5 unlocks; verified user 15/15 with next-unlock label; UV's Redis window deleted → still locked, earlier article reopens;
unverified 8/8; admin unlimited, users page allowances, dashboard counters; Cassandra history 15 rows after consume. Terra's "guest navbar
has no allowance" is pre-existing (navbar allowance inside @auth, unchanged from main). The fix round changed only decision internals
(settle loop, rebuild failure path, generation key, transfer window drop) covered by new tests.
