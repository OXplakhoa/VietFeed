# Gate 9.5 slice 4 — Source fetch history on Cassandra

## Implementation

### Plan

Branch `cutover/source-history`, HEAD `958640b`. Owner WIP hashes passed before edits. The baseline `composer run test` exited 0 with 386 tests and 7,764 assertions.

- Create `source_fetches_by_source_day` for one source, partitioned by `(source_id, day)`, and `source_fetches_by_day` for global recent attempts, partitioned by `day`. Both use UTC day buckets and cluster by `started_at DESC, attempt_id DESC`. Retain rows for 30 days from the attempt timestamp, with remaining TTL on replay and import. Use UUIDv7 for new attempts and a deterministic timestamp-plus-hash UUIDv7 for SQL IDs.
- Publish full attempt snapshots via the existing Redis `StreamConsumer`. Cassandra runs only in the consumer, so outages cannot block RSS fetches or Mongo summary updates. Keep manual queued/running/result transitions on a stable attempt key. Its key timestamp is the enqueue time and the displayed fetch start is captured when execution begins. Use increasing Cassandra write timestamps so late deliveries cannot roll back a newer state. Log failure types without event payload. Recover with retries and dead-letter replay.
- Read global recent 20 plus last 5 for each source on the displayed Health page and last 15 on the Show page. Query retained buckets newest first with CQL limits and look up global source labels in MongoDB. Omit deleted sources from the global view while their rows age out. On a history read error render the explicit unavailable state, with current Mongo metrics and actions. Add `sources:history --source= --days=` for the day/week demo.
- Import legacy SQL in ID chunks via `sources:import-history-sql`. Resolve old numeric source IDs through SQL feed URLs and current Mongo Sources, also accepting already-remapped IDs. Copy fields and original timestamps, using creation time for the key when there was no start. Count copied, expired and unresolved rows. Persist an import marker before copying so any repeat requires `--force`. Leave SQL rows alone and remove the slice 1 remap from `ConfigSqlImport` while keeping config and other reference remaps.
- Add `app/SourceHistory/{SourceFetchAttempt,SourceFetchHistory,SourceHistorySqlImport}.php` and `app/Console/Commands/{SourceHistory,SourceHistoryManage,ImportSqlSourceHistory}.php`. Change `FeedIngestionService`, `FetchSourceFeedJob`, `Admin/SourceController`, `Source`, `ConfigSqlImport`, both admin source history views and `routes/console.php`. Replace the SQL `SourceFetchLog` model. Document operations in `docs/database/nosql-local.md` and this report.
- Adapt `tests/Feature/{ConfigMongoTest,RedisQueueTest,SourceHealthMonitorTest}.php` to use a shared isolated source history fixture. Extend the existing guarded Redis teardown in `tests/TestCase.php` to clear this stream. Add `tests/Feature/SourceFetchHistoryTest.php` and `tests/Concerns/SourceHistoryFixtures.php`. Cover fake RSS success/warning/failure, Cassandra outage and recovery, real queue execution, both projections and page limits, UTC boundaries, import refusal and deterministic repair, and runtime SQL isolation. Run focused checks, each new or edited test file three times, the full suite, platform requirements, touched-file Pint, runtime SQL scan and final WIP checks.

### Decisions and outcomes

| Outcome | Implementation |
|---|---|
| 1. Cassandra design | Source-day partition `(source_id, day)` and global-day partition `day`. Both sort by `started_at DESC, attempt_id DESC`, use UTC days, and expire 30 days from the key timestamp. CQL writes use the remaining TTL. The 30-day deletion-marker grace protects cleared fields against older snapshots. For scheduled fetches and diagnostics, the key timestamp is the fetch start. For manual retries it is the enqueue time, and the separate `fetch_started_at` preserves the first execution start. |
| 2. Writers | Scheduled ingestion, RSS test and manual retry use the existing Redis Stream consumer pattern. New attempt IDs use Laravel UUIDv7. Queued, running and terminal snapshots share a key and use increasing CQL mutation timestamps across job retries. Snapshots write both projections before ACK. Cassandra connectivity and retries stay in the consumer. Mongo summary writes, retry backoff, RSS diagnostics and fetch outcome thresholds follow their existing paths. Publish and projection failures log the exception type. |
| 3. Readers | Source Health reads the global recent 20 and last five per displayed Source. Source Show reads the last 15. The source lookup groups up to seven UTC day partitions per CQL request, with a limit per partition. The global query walks day partitions and uses a clustering-key cursor to skip deleted Sources. Both pages show an explicit unavailable state on CQL failure and retain summary and actions. `sources:history --source=<id> --days=1\|7` lists one Source over UTC calendar days. |
| 4. Import | `sources:import-history-sql` reads ID-ordered chunks of 500, resolves numeric references via the legacy feed URL, and accepts already mapped Mongo IDs. The attempt timestamp and a namespaced SHA-256 hash of the SQL ID form a deterministic UUIDv7. It prints scanned, written, expired, unresolved-source and missing-timestamp totals. A conditional insert claims the persistent import marker before any rows are written. Normal repeats are refused, including after an interrupted or empty run. A forced repeat upserts the same keys from the same SQL snapshot. `ConfigSqlImport` now remaps articles and user favorites as before and leaves SQL history references to the history importer. |
| 5. Isolation and coverage | Live Cassandra and Redis tests use unique fixture identities and fake RSS. Tests cover both projections, lifecycle order, TTL, UTC boundaries, import, read failure and replay, and SQL query logging. The runtime source scan is recorded below. |
| 6. Docs | `docs/database/nosql-local.md` includes table keys, TTL, the one-day/week CLI and CQL queries, bootstrap and recovery commands, and the cutover runbook. |

### Test edits

| File | Edit and reason |
|---|---|
| `tests/Feature/ConfigMongoTest.php` | Read the scheduled and manual UUID references from projected history. Verify deletion still removes the Source from visible history. Replace the SQL rank assertion with exact Cassandra IDs for the 5/15 limits, the global 20 and descending order. Keep the existing config import and duplicate preflight checks, asserting that legacy SQL rows stay intact. Extend the static SQL config allowlist to the new offline importer. |
| `tests/Feature/RedisQueueTest.php` | Dispatch a real serialized Redis retry job, run the Redis worker, and check its Cassandra UUID, status and item count. The queue and database guards stay in use. |
| `tests/Feature/SourceHealthMonitorTest.php` | Check the same RSS diagnostics and queued state after projecting into Cassandra, plus the stable ID shared by the queued row and job. RSS calls are faked and stray requests are blocked. |
| `tests/Feature/SourceFetchHistoryTest.php` | Five tests exercise fake RSS success/warning/failure and Mongo summaries, both read models, refused Cassandra endpoint with live Redis retry and dead-letter replay, UTC rollover and day/week commands, delayed and duplicate lifecycle deliveries, schema grace, and SQL import retention, ID reuse, counts, refusal and forced repeat. |
| `tests/Concerns/SourceHistoryFixtures.php`, `tests/TestCase.php` | Bootstrap schema only in the guarded Cassandra test keyspace and clear only this module's import marker for the import test. Use unique source IDs for test histories. Clear source history stream keys in the existing live DB15 teardown. The trait and base class run through the concrete test suites. |

### Files changed

- Added `app/SourceHistory/{SourceFetchAttempt,SourceFetchHistory,SourceHistorySqlImport}.php` and `app/Console/Commands/{SourceHistory,SourceHistoryManage,ImportSqlSourceHistory}.php`.
- Updated `app/Services/Rss/FeedIngestionService.php`, `app/Jobs/FetchSourceFeedJob.php`, `app/Http/Controllers/Admin/SourceController.php`, `app/Models/Source.php`, `app/Content/ConfigSqlImport.php`, `routes/console.php`, and `resources/views/admin/sources/{health,show}.blade.php`. Removed the SQL `app/Models/SourceFetchLog.php` model. The existing SQL schema remains for legacy import.
- Added `tests/Concerns/SourceHistoryFixtures.php` and `tests/Feature/SourceFetchHistoryTest.php`. Updated `tests/TestCase.php` and `tests/Feature/{ConfigMongoTest,RedisQueueTest,SourceHealthMonitorTest}.php`.
- Updated `docs/database/nosql-local.md` and added this report.

### Cutover order

1. Pause RSS scheduling and manual retries. Drain old retry jobs on the old release because their serialized payloads carry a SQL log ID. Take a read-only legacy SQL backup for any data to be carried forward.
2. Deploy and run `php artisan sources:history-manage bootstrap` before starting the projection consumer. Fresh installations can seed Mongo configuration and collect history from normal RSS runs.
3. For legacy retention, import Mongo config first if needed. With the default connection pointed to the legacy SQL database, run `php artisan sources:import-history-sql` and review the counts. Keep the source IDs and input snapshot stable. Use `--force` to resume the same import when needed.
4. Resume traffic and workers. Verify `sources:history-manage inspect`, replay dead letters as needed, and run the source day/week query on a known Source. The old SQL table remains until slice 5.

### Known limitations

- The admin history is eventually consistent. A Redis publish failure or an undelivered stream entry lost from Redis leaves a history gap. The Mongo summary remains current.
- The two Cassandra projections can temporarily differ after an interrupted write. Stream replay completes both writes using the same primary keys.
- Deleted Sources are excluded from the global view while their history ages out in Cassandra. The normal scheduler volume is roughly 48 attempts per active source per day before diagnostics and retries.
- The shared CQL driver's nonblocking socket may wait on a connected peer that sends no response. The outage test exercises refused connections and shows the intended UI when CQL reports a failure.
- The import was exercised against isolated SQLite, MongoDB and Cassandra. Expired rows, unresolved Sources and rows with no timestamp are counted and skipped. SQL timestamp strings are interpreted in the application timezone as in the legacy model.

### Verification

All test runs were sequential. The test stores were Cassandra `vietfeed_test`,
MongoDB `vietfeed_test`, Redis DB15 with the existing live `getDbNum()` guard,
and SQLite `:memory:`. Logs are in `/tmp/vietfeed-95-4-*.log`. Each exit column
records the command's shell status after redirecting stdout and stderr.

| Command | Exit | Result |
|---|---:|---|
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` before edits | 0 | 6/6 OK |
| `git status --short` before edits | 0 | Captured owner WIP and the existing untracked task directory |
| `composer run test` baseline | 0 | 386 tests, 7,764 assertions |
| `vendor/bin/phpunit tests/Feature/SourceHealthMonitorTest.php` after initial writer and reader changes | 0 | 3 tests, 31 assertions |
| `vendor/bin/phpunit tests/Feature/SourceFetchHistoryTest.php` during implementation | 1 | The initial outage test assumed two retries per consumer poll. The shared consumer can retry a pending event within the same poll. The test now advances one delivery at a time. |
| `vendor/bin/phpunit tests/Feature/ConfigMongoTest.php tests/Feature/RedisQueueTest.php tests/Feature/SourceHealthMonitorTest.php tests/Feature/SourceFetchHistoryTest.php` during implementation | 1 | The static SQL config check detected the new offline importer's legacy Source mapping. The allowlist now names both offline importers by exact path. |
| Guarded PHP/CQL schema sync of the two slice 4 tables in `vietfeed_test` | 0 | Applied 2,592,000-second grace to the test tables created by early verification. The final test asserts both schema values. |
| `vendor/bin/phpunit tests/Feature/ConfigMongoTest.php` ×3 | 0 / 0 / 0 | 16 tests, 659 assertions each |
| `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` ×3 | 0 / 0 / 0 | 3 tests, 49 assertions each |
| `vendor/bin/phpunit tests/Feature/SourceFetchHistoryTest.php` ×3 | 0 / 0 / 0 | 5 tests, 135 assertions each |
| `vendor/bin/phpunit tests/Feature/SourceHealthMonitorTest.php` ×3 | 0 / 0 / 0 | 3 tests, 29 assertions each |
| `composer run test` final | 0 | 391 tests, 7,909 assertions |
| `composer check-platform-reqs` | 0 | All platform requirements satisfied on PHP 8.4.27 |
| `vendor/bin/pint --test` on the 20 touched PHP/Blade files | 0 | Passed. Explicit argument list in `/tmp/vietfeed-95-4-pint-files`. |
| `rg -n --hidden 'source_fetch_logs' app bootstrap config database/factories database/seeders resources routes scripts` | 0 | Three matches, all in `app/SourceHistory/SourceHistorySqlImport.php` (lines 19, 20, 36). Its chunked query is the sole SQL history reader. Tests and legacy schema migrations are outside this runtime scan. |
| `git diff --check -- app resources routes tests docs/database/nosql-local.md` | 0 | No whitespace errors |
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` after implementation | 0 | 6/6 OK |
| `git status --short` after implementation | 0 | Only slice changes added to the baseline WIP listing. See `/tmp/vietfeed-95-4-status-after.log`. |

**STATUS: READY_FOR_REVIEW**

## Fix round (REVIEW-1)

| Finding | Control decision and implementation |
|---|---|
| F1 | `sources:history-manage consume` checks a monotonic 35-second deadline between deliveries. With PCNTL, SIGALRM terminates a stuck delivery at 45 seconds, before the one-minute scheduler mutex expires. A scheduler comment explains why this task runs in the background. A budget regression verifies the consumer stops before claiming the next delivery. The shared StreamConsumer API and Cassandra client retain their existing behavior. Page-request timeouts remain a follow-up. |
| F2 | The history publisher scrubs invalid UTF-8 before encoding the event. The retry action publishes the queued snapshot after the bus returns from dispatch. Tests cover a malformed diagnostic that still projects and a dispatch failure that leaves the stream empty. |
| F3 | The local NoSQL guide documents stream and dead-letter queue eviction, the five-attempt retry budget, operator recovery with `inspect` and `replay`, and the Mongo health summary during projection outages. |
| F4 | Source Health queries only today and the six preceding UTC days for both history lists. The boundary test covers both lists, Source Show and the demo command. Browser acceptance noted pre-existing presentation details that stay outside this fix. |
| F5 | Absent fields and incidental nulls are omitted from mutations. A successful outcome can explicitly clear the two error fields, including a successful fetch classified as a warning. The legacy import omits SQL NULLs. Cassandra tests cover sparse lifecycle writes and an out-of-order replay that preserves newer outcome fields. |

The first implementation extended the shared StreamConsumer signature and broke
its existing InteractionStream override. The PHP process terminated when
`ApiTokensTest` loaded the boost path. The budget loop now lives in the Source
History module. The hard alarm was also tested separately against a local TCP
peer that accepted the connection and sent no replies. The probe checks that
the process terminates and Redis retains the unacknowledged delivery.

The 45-second hard stop depends on PCNTL. Without it, the deadline still limits
work between deliveries, and a blocked socket follows the shared driver's
behavior.

### Verification

All suites ran sequentially on the same isolated stores as the initial slice.
Logs are in `/tmp/vietfeed-review1-*.log`.

| Command | Exit | Result |
|---|---:|---|
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` before and after | 0 / 0 | All six hashes matched |
| `composer run test` during implementation | 2 / 2 / 2 | The intermediate shared method signature failed when the boost API loaded its existing override. Fixed by moving the budget loop into Source History. |
| `vendor/bin/phpunit tests/Feature/SourceFetchHistoryTest.php` ×3 | 0 / 0 / 0 | 8 tests, 177 assertions each |
| `vendor/bin/phpunit tests/Feature/SourceHealthMonitorTest.php` ×3 | 0 / 0 / 0 | 4 tests, 38 assertions each |
| `composer run test` final | 0 | 395 tests, 7,960 assertions |
| `python3 /tmp/vietfeed-review1-silent-peer.py` | 0 | The command terminated via SIGALRM (process status −14) at 45.28 seconds. The DB15 test prefix had one pending delivery and no dead letters. The probe removed its keys. |
| `vendor/bin/pint --test app/Console/Commands/SourceHistoryManage.php app/Http/Controllers/Admin/SourceController.php app/SourceHistory/SourceFetchHistory.php app/SourceHistory/SourceHistorySqlImport.php routes/console.php tests/Feature/SourceFetchHistoryTest.php tests/Feature/SourceHealthMonitorTest.php` | 0 | All seven fix-round PHP files passed |
| `git diff --exit-code -- app/Processing/StreamConsumer.php app/StoryProcessing/CassandraClient.php` | 0 | Both shared implementations match HEAD |
| `rg -n --hidden 'source_fetch_logs' app bootstrap config database/factories database/seeders resources routes scripts` | 0 | All three runtime-path matches remain in the offline history importer |
| `git status --short` | 0 | Same paths and status codes as the initial slice submission. Output in `/tmp/vietfeed-review1-status.log`. |

**STATUS: READY_FOR_REVIEW**

## Control acceptance (2026-11-18, Claude Opus 5.5 control)

Workflow: implementation GPT 6.2 Sol; independent reviews Claude Opus — REVIEW-1.md PASS_WITH_NOTES (five MINORs fixed), REVIEW-2.md
PASS_WITH_NOTES (remaining MINOR is display-only: sparse snapshots may show a previous retry execution's http_status/error until the
next success — known limitation). Follow-up (separate task): bounded read timeout in the shared CassandraClient for page requests.

| Check | Result |
|---|---|
| `composer run test` (control, after fix round) | exit 0 — 395 tests / 7960 assertions (main 958640b: 386 / 7764) |
| `pint --test` touched PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| Runtime references to source_fetch_logs / SourceFetchLog | none (only `SourceHistorySqlImport`) |
| pcntl in PHP CLI | enabled (hard stop for the consumer works) |
| Owner WIP sha256 | unchanged |

Browser (GPT 6.1 Terra, before the fix round, isolated stores, 2 real admin retries): Health 200; consumer processed 6 lifecycle events;
global table and source pages list the attempts; `sources:history` and cqlsh show rows in both tables; Mongo summaries updated as on main
(failed fetch leaves last_fetched_at unchanged, as on main). Terra's step-3 remarks (no per-source attempt list in rows, no duration
column in history tables) are views unchanged from main — the control criterion was wrong; parity PASS. Dân Trí – Giáo dục feed returns
HTTP 404 (owner seed data; reported to the owner).
