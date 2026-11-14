# Gate 5 / #15

## Implementation

Worker: single implementation worker (requested gpt-6.1-sol medium); Control: Fable 5.2 Medium.
Base: 2db3e16304953fe6f593abeaacf4d7a2ff550b07; branch redis/streams-15. No commits or delegation.

### Owned-file plan and behavioral tests (before implementation)
- Configuration: config/database.php, config/nosql.php; dedicated nosql connection, DB2 default.
- Domain: app/Interaction/{InteractionEvent,InteractionReplaySource,SyntheticInteractionReplaySource,Trending,InteractionStream}.php; container binding in app/Providers/AppServiceProvider.php.
- CLI: app/Console/Commands/Interactions.php (bounded consume, pending/DLQ inspection, replay, top, rebuild).
- Tests: tests/Feature/RedisStreamsTest.php, tests/Fixtures/interactions-v1.json, tests/Concerns/GuardsRedisTestDatabase.php; reuse guard in RedisQueueTest.php and RedisSessionTest.php; strengthen tests/TestCase.php isolation before mutations.
- Append documentation to docs/database/nosql-local.md and finish this report.
- TDD seams authorized by ticket: consumer/retry, trending apply/top, replay-source/rebuild; command inspection and rebuild.
- Behavioral list: valid UUIDv7 event envelope; deterministic boost=5/view=1 scores; duplicate and stale versions ignored; handler-applied/unACKed delivery recovered without inflation; poison ends after five attempts with empty input/pending and one DLQ; retry backoff; native prefix behavior; missing projection components fail closed; stream/group recreation; replay; deterministic rebuild; guarded DB15 flush retains Mongo and SQLite entities and requires re-login.

### Baseline memory
Redis 10.48 MiB / 256 MiB; Cassandra 1.447 GiB / 1.5 GiB; Mongo 165 MiB / 768 MiB; Neo4j 618.1 MiB / 1 GiB.

### Decisions made and why

1. Added the real Laravel `database.redis.nosql` connection (DB2 default), and corrected the older `nosql.redis_nosql.database` fallback to 2. The dedicated connection deliberately ignores REDIS_URL so its path cannot silently redirect Streams to the default/cache DB. Existing default/session DB0 and cache DB1 configuration is unchanged. Active `.env` untouched.
2. Domain seams are `InteractionEvent`, `InteractionStream`, `Trending` and `InteractionReplaySource`. CLI delegates all Redis work to them. Event envelopes require UUIDv7 event/aggregate IDs, positive integer version, explicit RFC3339 occurrence time, supported type, and article ID. Each fixture aggregate identifies an independent immutable interaction, rather than an Article-wide sequence. Stale/equal aggregate versions and duplicate event IDs do not score.
3. Weights are boost=5, view=1, no decay/unboost. Five disk-fixture events give article-a=7 and article-b=6. These article IDs are explicitly synthetic aliases. Current weighting is a thin proof, not a product algorithm.
4. Chose a single Sorted Set as the score/dedupe/version eviction unit, replacing the initial plan's separate-component failure detection: positive `article:` scores, zero `event:` markers, negative `version:` markers. Lua applies all updates atomically; top filters metadata. This prevents score surviving the eviction of its dedupe key. Whole-key loss requires full replay; tested deletion/rebuild demonstrates this boundary.
5. Stream PEL delivery count is the attempt budget: five maximum, 1/2/4/8-second retry backoff, XPENDING/XCLAIM interrupted-consumer recovery. Final interrupted attempt is reclaimed with JUSTID (does not increment delivery count), then DLQed without invoking a sixth handler. Terminal Lua writes DLQ + ACK + input/error cleanup atomically. Success ACK follows projection apply; apply-before-ACK recovery is tested. Successful/terminal inputs are deleted, so the input Stream is never treated as durable history.
6. Implemented `InteractionReplaySource` with a versioned synthetic fixture on disk. No Cassandra adapter, fifth database or dependency changes. Rebuild stages a fresh key and renames on success; a source failure preserves the old ranking. Operators pause writers during rebuild. `--synthetic` is required at the CLI to acknowledge the current adapter's scope.
7. Extracted the #14 live DB15 flush assertion into a shared trait and retained its DB0 URL-override rejection test. TestCase now refuses cached config and verifies Mongo/SQLite configuration before RefreshDatabase migration, pins all Redis configs to DB15 with URL=null before tests, and verifies Mongo database again immediately before teardown deletion. The trait checks live Redis getDbNum; every flush checks it again. Initial test scaffolding exposed a Laravel RedisManager config snapshot: mutating config after resolving that manager did not retarget later connections. The trait now configures all names before rebuilding the manager; the guard rejected DB0 before any flush in that initial failing run.
8. Verified on PHP 8.4.27, ext-redis/phpredis 6.3.0, installed Laravel 13.11.2: native stream/zset commands and EVAL key arguments apply the configured prefix; rawCommand does not. Tests check physical prefixed keys and use native reads/ACKs plus Lua writes. No runtime/dependency changes were needed.
9. Kept Compose unchanged. Documented that allkeys-lru can lose input/PEL/DLQ/retry state and delivery budgets. Group recreation restores availability, not missing facts. Entire-ranking eviction is recoverable from the external source; current source covers synthetic data only. No production exactly-once or durable queue claim.
10. Distributed fetch/projection locks explicitly deferred: wiring a correct lease/renewal boundary into Source Health and live Gate 6 projection work is larger than this proof. An unused lock would not establish the required workflow and can itself be evicted. Consumer PEL leases are not claimed as fetch locks.

### Files created/changed (owned only)

Created:
- `app/Interaction/InteractionEvent.php`
- `app/Interaction/InteractionReplaySource.php`
- `app/Interaction/SyntheticInteractionReplaySource.php`
- `app/Interaction/Trending.php`
- `app/Interaction/InteractionStream.php`
- `app/Console/Commands/Interactions.php`
- `tests/Concerns/GuardsRedisTestDatabase.php`
- `tests/Feature/RedisStreamsTest.php`
- `tests/Fixtures/interactions-v1.json`
- `.agent/tasks/redis-cutover/GATE-REPORT-15.md`

Changed:
- `config/database.php`
- `config/nosql.php`
- `app/Providers/AppServiceProvider.php`
- `tests/TestCase.php`
- `tests/Feature/RedisQueueTest.php`
- `tests/Feature/RedisSessionTest.php`
- `docs/database/nosql-local.md` (append only)

Owner WIP was not edited: three older STATUS files, CLAUDE.md, SourceSeeder.php, all existing task packets, .pi/, docs/agents/, pi-warden.md. No commits, stash, push, PR, delegation, dev-data migrations, volume operations, dependency changes, active .env changes or Compose edits.

### Acceptance — implementation evidence

| Criterion | Result | Evidence |
|---|---|---|
| T3 poison bounded DLQ | PASS | Five attempts, one DLQ, zero input and PEL; no further retry |
| T3 fixture trending output | PASS | Independent literal scores 7 and 6; top CLI tested |
| T3 flush/re-login/durable counts | PASS | Mongo users/articles/stories + SQLite bookmarks/subscriptions all 1 before/after; HTTP guest/re-login and ranking restored |
| T3 full suite + Pint | PASS | Review-fix round 1: 84 tests / 932 assertions; 15 owned PHP files clean; Streams 26 / 417 on each of 3 consecutive runs |
| Outcome 1 group/retry/backoff/recovery/ACK/DLQ | PASS | XGROUP/XREADGROUP/XPENDING/XCLAIM/XACK; transient recovery and final-interruption tests |
| Outcome 2 poison terminal bound | PASS | Stream=0, pending=0, DLQ=1, attempts=5, error + original JSON retained |
| Outcome 3 contract/idempotency/interruption | PASS | Invalid contract matrix, duplicate/equal/lower version tests; applied-unACKed entry recovered by a new consumer |
| Outcome 4 Sorted Set top-N | PASS | Lua ZINCRBY/ZADD metadata, ZREVRANGE top; synthetic fixture a=7,b=6 |
| Outcome 5 replay source outside Redis | PASS | Versioned disk fixture behind interface; repeated/failed/empty rebuild tests; Cassandra deferred |
| Outcome 6 isolated flush/rebuild | PASS | Live DB15 guard; login + session/cache/stream/ranking populated then flushed; durable counts and re-login assertions |
| Outcome 7 dedicated config and prefix | PASS | Both defaults DB2, dedicated nosql connection; live physical-key prefix test; tests DB15 |
| Outcome 8 CLI | PASS | consume (limit1 for once, max100), inspect, replay, top, rebuild; inspection/rebuild/top/replay exercised |
| Outcome 9 allkeys-lru loss resolution | PASS | One-key score/dedupe eviction unit, tested loss/rebuild and stream/group recreation; loss limitations documented; Compose untouched |
| Outcome 10 lock or explicit deferral | PASS | Explicit scoped deferral with Source Health/Gate 6 lease/eviction reason |
| Outcome 11 append local operations docs | PASS | Appended names, connections, delivery semantics, commands, replay source and loss recovery |
| Independent Review / owner manual acceptance | PENDING RE-REVIEW | Initial independent review FAIL; accepted findings repaired in review-fix round 1; no independent PASS or MANUAL_PASS claimed |

### TDD and verification evidence

All mutating invocations were sequential under the exclusive writer/test slot.
Before mutations: no bootstrap/cache/config.php; effective Mongo vietfeed_test,
SQLite :memory:, Redis test connections DB15 with URL null. Final guards run in
TestCase before migrations and again on live connections before each flush.
No FLUSHALL or flush of development DB0/1/2 was performed.

TDD progression through the requested interfaces:
- Initial isolation scaffold: exit 1 (live connection was DB0; assertion stopped before flush). Fixed manager config capture.
- Trending interface red: exit 2 (class missing), then green: 1 test / 9 assertions, exit 0.
- Consumer/retry red: exit 2 (class missing), then green: 2 tests / 29 assertions, exit 0.
- Interruption/rebuild slice red: exit 2 (replay-source binding missing; interruption test already passed), then green: 4 tests / 55 assertions, exit 0.
- CLI inspection/rebuild red: exit 2 (command missing), then green: 5 tests / 69 assertions, exit 0.
- Flush and prefix slice: 7 / 123, exit 0. Expanded contract/retry/replay/rebuild evidence: 12 / 210, exit 0.
- Final inspection tightened terminal recovery to JUSTID, avoiding even a PEL counter increment beyond five. All final required tests were rerun after this change.

Each final command below was a separate invocation, with actual process status;
no tail/tee or pipeline masking. `composer run test` was awaited through its
original process completion.

| Final command | Exit | Result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` run 1 | 0 | 12 tests / 210 assertions |
| same, run 2 immediately following | 0 | 12 / 210 |
| same, run 3 immediately following | 0 | 12 / 210 |
| `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` | 0 | 3 / 42 |
| `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` | 0 | 4 / 47 |
| `composer run test` | 0 | 70 / 725 |
| targeted `vendor/bin/pint --test` below | 0 | All 14 created/changed PHP files clean |
| `git diff --check` | 0 | Clean |
| `./scripts/nosql-health.sh` | 0 | Mongo/Cassandra/Neo4j/Redis: 4/4 READY |
| `GATE15_DLQ_PROOF=1 vendor/bin/phpunit tests/Feature/RedisStreamsTest.php --filter=test_dlq_inspection_command_proof_and_replay` | 0 | 1 / 24; invokes real `interactions inspect` on guarded live DB15 |

Exact Pint invocation:

```bash
vendor/bin/pint --test app/Interaction/InteractionEvent.php app/Interaction/InteractionReplaySource.php app/Interaction/SyntheticInteractionReplaySource.php app/Interaction/Trending.php app/Interaction/InteractionStream.php app/Console/Commands/Interactions.php app/Providers/AppServiceProvider.php config/database.php config/nosql.php tests/Concerns/GuardsRedisTestDatabase.php tests/Feature/RedisStreamsTest.php tests/Feature/RedisQueueTest.php tests/Feature/RedisSessionTest.php tests/TestCase.php
```

Final DLQ inspection output (before tested explicit replay and DB15 cleanup):

```json
{
  "lengths": {"stream": 0, "pending": 0, "dead_letters": 1},
  "pending_entries": [],
  "dead_letter_entries": {
    "1794677244203-0": {
      "original_id": "1794677244163-0",
      "attempts": "5",
      "last_error": "Synthetic projection outage"
    }
  }
}
```

The actual printed entry also contained the complete original synthetic boost
event JSON with UUIDv7 IDs, aggregate version, article alias and occurrence time;
the test asserts original envelope retention and replay idempotency. The separate
poison test asserts the invalid-type original envelope is retained as well.

### Memory after verification

`docker stats --no-stream --format '{{.Name}} {{.MemUsage}}'` exited 0.

| Store | Before | After | Container ceiling |
|---|---|---|---|
| Redis | 10.48 MiB | 11.25 MiB | 256 MiB |
| Cassandra | 1.447 GiB | 1.451 GiB | 1.5 GiB |
| Mongo | 165 MiB | 317.4 MiB | 768 MiB |
| Neo4j | 618.1 MiB | 618.5 MiB | 1 GiB |

No synthetic load beyond tiny deterministic test fixtures was started. Cassandra
remains close to its cap; no resource settings were changed.

### Limitations and deferred items

- READY_FOR_REVIEW is the implementation handoff, not independent Review PASS,
  production acceptance, owner manual acceptance, or approval to commit/merge.
- Redis loss resets operational delivery state and may erase DLQ/errors; the
  five-attempt guarantee covers the surviving PEL, not a lifetime across data loss
  or an explicit operator replay. Whole ranking/dedupe eviction requires replay.
- Current replay is deterministic synthetic disk data only. Real durable
  interaction capture and Cassandra activity-by-article/day adapter are Gate 6.
- Rebuild requires paused writers; temporary keys can remain after process death.
  Large replay, eviction during staging, renewable leases, concurrency stress,
  durable failure auditing and retention/compaction are not established by this
  small proof. Recovery pagination is covered in review-fix round 1; inspection
  still scans at most 1000 pending / 100 DLQ.
- Distributed RSS fetch/live projection lock wiring is explicitly deferred.
- StoryLens pipeline, AI, Billing, AT/RT, Cassandra/Neo4j projections and broad SQL
  migration are not implemented. Existing SQLite fixture use and SQL-backed
  business tables remain; the flush proof is not full SQL-removal evidence.
- Active local driver selection and historic MySQL queue drainage were not
  revalidated or changed. No live external product producer is attached.
- Skills' older slash modes (`ponytail`, `typesafe`, `warden`) are not available
  as callable capabilities here; no activation or delegated-review claim is made.

### PROPOSED ATOMIC COMMIT GROUPING

Control owns committing. Exact paths only; exclude all owner WIP and old packets.
Keep closely coupled domain primitives together rather than introducing a
consumer whose default handler/replay dependencies are absent. Existing suite
should remain green after each group; full new acceptance coverage arrives in 3.

1. `fix(redis): isolate NoSQL DB2 and share guarded test connections` ->
   `config/database.php`, `config/nosql.php`, `tests/TestCase.php`,
   `tests/Concerns/GuardsRedisTestDatabase.php`,
   `tests/Feature/RedisQueueTest.php`, `tests/Feature/RedisSessionTest.php`.
2. `feat(interactions): add bounded streams and rebuildable idempotent trending` ->
   `app/Interaction/InteractionEvent.php`, `app/Interaction/InteractionStream.php`,
   `app/Interaction/Trending.php`, `app/Interaction/InteractionReplaySource.php`,
   `app/Interaction/SyntheticInteractionReplaySource.php`,
   `app/Providers/AppServiceProvider.php`, `tests/Fixtures/interactions-v1.json`.
3. `feat(cli): expose interaction recovery and verify isolated Redis loss` ->
   `app/Console/Commands/Interactions.php`, `tests/Feature/RedisStreamsTest.php`.
4. `docs(redis): document Gate 5 recovery evidence and boundaries` ->
   `docs/database/nosql-local.md`, `.agent/tasks/redis-cutover/GATE-REPORT-15.md`.

## Review-fix round 1

Status: **READY_FOR_REVIEW** (implementation evidence only). Branch
`redis/streams-15`, HEAD `2db3e16`; all changes remain uncommitted. Control accepted
the independent FAIL findings below. This section supersedes the initial test
counts and recovery limitations above; earlier verification tables are historical.

### Findings and changes

| Finding | Status | Change and regression evidence (current file:line) |
|---|---|---|
| P1-a unchecked apply EVAL | RESOLVED | `app/Interaction/RedisResult.php:12` clears stale native error state, checks both command reply and getLastError, wraps thrown/false/error replies with operation context, and clears the consumed error. `app/Interaction/Trending.php:29` accepts exactly integer 0 (duplicate/stale) or 1 (applied). Driver contract at `tests/Feature/RedisStreamsTest.php:308`; real ranking WRONGTYPE delivery/DLQ regression at `:324`. |
| P1-b unchecked ACK/DLQ/replay/rebuild | RESOLVED | `app/Interaction/InteractionStream.php:148` checks ACK EVAL and performs potentially failing error cleanup before ACK/deletion. `:162` verifies pending status and cleanup type before DLQ XADD; DLQ must exist before ACK/XDEL; each redis.call error propagates through checked EVAL, and ACK/delete outcomes are checked explicitly. `:220` distinguishes missing DLQ ID from Redis failure, confirms XADD before XDEL, and validates the returned stream ID. `app/Interaction/Trending.php:53` checks staging ZADD, each event apply, RENAME, and failure cleanup; successful RENAME needs no redundant DEL. Tests at `tests/Feature/RedisStreamsTest.php:341`, `:369`, `:408`, `:426`, and `:509` cover real DLQ WRONGTYPE, rebuild reply injection, ACK cleanup WRONGTYPE, replay WRONGTYPE and DLQ cleanup type preflight. |
| P2-a PEL starvation after first page | RESOLVED | `app/Interaction/InteractionStream.php:21` adds injectable page size 1..1000; `:183` walks exclusive XPENDING ID cursors until exhausted. `:54` uses this iterator for recovery; inspection remains separately bounded. `tests/Feature/RedisStreamsTest.php:445` proves eligible row 3 progresses past two ineligible rows with page size 2; attempts remain 2 for the recovered row and 1 for untouched rows. |
| P2-b UUID case identity | RESOLVED | `app/Interaction/InteractionEvent.php:35` canonicalizes event_id and aggregate_id to lowercase after validation. `tests/Feature/RedisStreamsTest.php:461` publishes upper/lower redeliveries and a new event ID at the same differently-cased aggregate/version: score stays 5, both envelope fields are canonical. |
| Control-1 missing pending payload | RESOLVED | `app/Interaction/InteractionStream.php:69` atomically checks payload existence/field before XCLAIM. Missing payload bypasses XCLAIM (which otherwise silently removes its PEL record on this Redis), writes an explicit missing-payload DLQ with empty event, original ID and actual attempt count, then ACKs. Terminal recovery still uses JUSTID and never calls handler six. `tests/Feature/RedisStreamsTest.php:488` covers deleted input at attempts 1 and 5, one DLQ, zero PEL, no handler and no repeat loop. |
| P3 shared TestCase preconditions / docs | RESOLVED | Guard retained in `tests/TestCase.php:11` and `:33`; documented for every TestCase subclass, including explicit integration invocations, in `docs/database/nosql-local.md:234`. Mongo, Neo4j and Cassandra integration suites pass with the guard unchanged. Gate 5 docs also correct paged recovery vs bounded inspection, lowercase UUIDs, checked Redis writes, missing-payload records, Lua non-rollback, staging promotion and limits (`docs/database/nosql-local.md:128`). |

Installed-driver verification used the actual Laravel nosql connection on guarded
DB15, before introducing the apply fix. A real EVAL ZADD against a string returned
**false**; native `getLastError()` contained **WRONGTYPE**; `clearLastError()` returned
**true**, then `getLastError()` returned **null**. Subsequent EVAL `return 0` and
`return 1` returned integer 0 and 1. Reflection over installed Redis `OPT_*`
constants exposed no THROW/ERROR configuration option. This is an executable
contract test of the installed driver, not an assumption about other versions or
a global option change.

Lua atomicity means no command interleaving, **not rollback**. Cleanup is ordered
so its type errors cannot remove unrecorded pending input. DLQ/replay write the
destination before removing their source. Idempotent ACK/DLQ no-op result 0 means
the ID is no longer pending; it is distinct from phpredis false. Live Redis errors
are not silently treated as those valid no-op results.

### Red -> green evidence

Commands below are focused PHPUnit invocations of
`vendor/bin/phpunit tests/Feature/RedisStreamsTest.php --filter='<filter>'`.
Each ran sequentially; red was observed before that slice's production change.
Counts are tests/assertions; no red is claimed for already-fixed behavior.

| Slice / filter | Red (exit, tests/assertions) | Green (exit, tests/assertions) |
|---|---|---|
| `test_installed_phpredis_eval_error_contract\|test_real_projection_error_stays_pending_then_reaches_dlq` | 1, 2/27; driver contract passed, input unexpectedly disappeared | 0, 2/34; pending retained and real error DLQed at five |
| `test_failed_dlq_write_reports_failure_and_preserves_terminal_input` | 1, 1/10; CLI incorrectly exited 0 | 0, 1/16; CLI exits 1, input/PEL preserved, repair yields one DLQ without sixth handler |
| `test_failed_rebuild_command_preserves_old_ranking_and_exits_nonzero` (initial test name) | 1, 3/34; command-boundary decorator: ZADD and RENAME false were missed, EVAL already passed after P1-a | 0, 3/42; every failure exits 1, old score 5 preserved, staging removed |
| `test_ack_cleanup_error_does_not_lose_pending_input\|test_replay_redis_error_is_contextual_and_preserves_dead_letter` | 2, 2/20; ACK swallowed error; replay mislabeled Redis failure as absent DLQ | 0, 2/26; contextual errors and preserved source records |
| `test_receive_recovers_eligible_pending_beyond_first_page` | 2, 1/9; missing injectable page-size seam | 0, 1/16; recovery reaches second page with exact observed attempt counts |
| `test_uuid_case_redelivery_and_aggregate_versions_share_canonical_identity` | 1, 1/10; score was 10 instead of 5 | 0, 1/13; score stays 5 and IDs canonical |
| `test_missing_pending_payload_is_recorded_and_removed_without_handler` | 1, 2/22; Redis removed PEL silently, DLQ stayed empty for both attempts 1/5 | 0, 2/34; one explicit missing-payload DLQ per case, no repeated processing |
| `test_rebuild_command_results_preserve_old_ranking_or_confirm_promotion` (final test name, fourth data row adds DEL failure) | 1, 4/52; redundant DEL incorrectly made a confirmed promotion fail | 0, 4/55; staging failures preserve old ranking, confirmed RENAME succeeds without DEL |

Supplemental already-green DLQ cleanup-type preflight regression:
`--filter=test_dlq_cleanup_type_error_cannot_partially_remove_input`, exit 0,
1 test / 13 assertions. A real retry-error-key WRONGTYPE leaves input=1, PEL=1,
DLQ=0; repair terminalizes once.

Test-harness corrections: early Mockery method-level stubs did not reliably
intercept the connection's command dispatch (initial 3-failure/1-risky scaffold);
replaced with a PhpRedisConnection command-boundary decorator, all other calls
real. The first DLQ green attempt also exposed draining Artisan output on a second
read (exit 1, 1/12); capture once fixed the assertion. Those scaffolds are not used
as failure-injection proof. The table records the corrected red/green evidence.

### Final verification (after the last PHP change)

Every row below is a **separate command invocation**, awaited to its actual exit
status. The first three commands ran consecutively with no intervening test.
No tail/tee/pipeline masked status. All mutating tests held the exclusive slot.

| Command | Exit | Result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` run 1 | 0 | 26 tests / 417 assertions |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` run 2 | 0 | 26 / 417 |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` run 3 | 0 | 26 / 417 |
| `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` | 0 | 3 / 42 |
| `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` | 0 | 4 / 47 |
| `composer run test` | 0 | 84 / 932; config:clear succeeded; original process awaited |
| `vendor/bin/phpunit tests/Integration/MongoConnectionTest.php tests/Integration/MongoAuthDoubleTest.php` | 0 | 3 / 19 |
| `vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php` | 0 | 4 / 35 |
| `vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php` | 0 | 3 / 18 |
| exact Pint invocation below | 0 | All 15 #15 PHP files pass |
| `git diff --check` | 0 | No whitespace errors |
| `./scripts/nosql-health.sh` | 0 | Mongo, Cassandra, Neo4j, Redis: 4/4 READY |

```bash
vendor/bin/pint --test app/Interaction/InteractionEvent.php app/Interaction/InteractionReplaySource.php app/Interaction/SyntheticInteractionReplaySource.php app/Interaction/Trending.php app/Interaction/InteractionStream.php app/Interaction/RedisResult.php app/Console/Commands/Interactions.php app/Providers/AppServiceProvider.php config/database.php config/nosql.php tests/Concerns/GuardsRedisTestDatabase.php tests/Feature/RedisStreamsTest.php tests/Feature/RedisQueueTest.php tests/Feature/RedisSessionTest.php tests/TestCase.php
```

Earlier full passes (25 Streams / 404 assertions; full suite 83 / 919) predated the
redundant-cleanup regression and are superseded by the final counts above.

### Limitations / remaining work

No accepted finding is left PARTIAL or unfixed. This is ready for independent
re-review, not its PASS, owner manual acceptance, commit authorization or merge.

- Redis remains ephemeral under allkeys-lru. Lua provides no rollback or durable
  failure audit; tested ordering prevents the reported wrong-type data-loss cases,
  not Redis/server loss or an ambiguous transport disconnect after server execution.
- Rebuild preserves the old ranking for the tested rejected staging/apply/RENAME
  operations; it requires paused writers. No concurrent ingestion, eviction during
  staging or connection-loss ambiguity guarantee is claimed. Failed cleanup can
  leave staging for operator inspection; successful rename needs no cleanup.
- Recovery pages beyond the first window, but scanning cost grows with PEL size.
  Inspection is still oldest 1000 pending / 100 DLQ; large/concurrent workload and
  renewable-lease behavior remain outside this proof.
- Missing-payload DLQ entries contain an empty event and reason, not recoverable
  history. Operator replay of such a record cannot reconstruct the missing event.
- Mongo teardown deletes only guarded test collections. Existing Cassandra spike
  leaves random test partitions in `vietfeed_spike`; integration compatibility
  does not establish production projections or durable interaction replay.
- Current replay source remains the synthetic v1 disk fixture. Cassandra replay,
  product producer wiring and distributed fetch/projection locks remain deferred.

Only the existing #15-owned domain/test/docs paths were changed this round, plus
new `app/Interaction/RedisResult.php`. Owner WIP and prior task packets preserved.
No delegation, commits, stash, push, dependencies, Compose, .env, dev-data
migrations, FLUSHALL or flush outside guarded DB15.

### Updated PROPOSED ATOMIC COMMIT GROUPING

Control owns committing. Group membership changes only to add RedisResult to the
domain commit; exclude all owner WIP and older task packets.

1. `fix(redis): isolate NoSQL DB2 and share guarded test connections` ->
   `config/database.php`, `config/nosql.php`, `tests/TestCase.php`,
   `tests/Concerns/GuardsRedisTestDatabase.php`,
   `tests/Feature/RedisQueueTest.php`, `tests/Feature/RedisSessionTest.php`.
2. `feat(interactions): add bounded streams and rebuildable idempotent trending` ->
   `app/Interaction/InteractionEvent.php`, `app/Interaction/InteractionStream.php`,
   `app/Interaction/Trending.php`, **`app/Interaction/RedisResult.php`**,
   `app/Interaction/InteractionReplaySource.php`,
   `app/Interaction/SyntheticInteractionReplaySource.php`,
   `app/Providers/AppServiceProvider.php`, `tests/Fixtures/interactions-v1.json`.
3. `feat(cli): expose interaction recovery and verify isolated Redis loss` ->
   `app/Console/Commands/Interactions.php`, `tests/Feature/RedisStreamsTest.php`.
4. `docs(redis): document Gate 5 recovery evidence and boundaries` ->
   `docs/database/nosql-local.md`, `.agent/tasks/redis-cutover/GATE-REPORT-15.md`.

## Review-fix round 2

STATUS: **READY_FOR_REVIEW**. Single implementation worker; Control: Fable 5.2
Medium. Branch `redis/streams-15`, HEAD `2db3e16`; all work remains uncommitted.
This section supersedes earlier blanket atomicity/error-surfacing claims and
verification counts. Independent Review and owner manual acceptance are pending.

### Per-item results

| Item | Result | Change and current file:line |
|---|---|---|
| P2-a fresh missing event | RESOLVED | `app/Interaction/InteractionStream.php:111` routes a fresh entry lacking `event` through the existing missing-payload deadLetter path at attempt 1. Original ID, empty payload and explicit reason are preserved; no handler, crash or repeat loop. `tests/Feature/RedisStreamsTest.php:200` XADDs `other=malformed`, verifies one DLQ row, empty input/PEL, and no second DLQ on another consume. |
| P2-b XREADGROUP false | RESOLVED | `app/Interaction/InteractionStream.php:104` uses existing `RedisResult::run` (`app/Interaction/RedisResult.php:12`), accepting array or false only after checking native last-error state. Empty read exits 0; real false + WRONGTYPE exits 1 with XREADGROUP context. `tests/Feature/RedisStreamsTest.php:215` injects a real key-type race after XPENDING via a native Redis subclass: it checks live DB15 before replacing the test stream, then calls parent XREADGROUP and asserts its actual false reply. No fake reply, ACL change or non-test DB mutation. |
| P2-b retry-error HSET | RESOLVED | `app/Interaction/InteractionStream.php:145` wraps HSET with command-specific integer 0/1 validation (existing field/new field). Failure escapes consume to CLI exit 1 without ACK, deletion, immediate re-delivery or resetting attempts. Existing PEL lease/backoff and five-handler-attempt budget remain. `tests/Feature/RedisStreamsTest.php:258` uses a real wrong-type errors key: attempts 1–4 report HSET failure, attempt 5 reports terminal DLQ preflight failure, another recovery stays at 5, and repair allows exactly one DLQ without handler invocation six. |
| Terra P1 Lua partial writes | ACCEPTED LIMITATION / DOCUMENTED | `docs/database/nosql-local.md:222` records the four scripts, triggers, consequences and Gate 6 work below. `app/Interaction/InteractionStream.php:236` qualifies the replay comment. No Lua reordering or new mechanism. |
| Rebuild outcome / error wording | DOCUMENTED | `docs/database/nosql-local.md:207` qualifies old-ranking survival around RENAME; `:153` enumerates checked operations and remaining unwrapped calls. Handled apply failures may exit 0 after recording retry/DLQ; read, recording, ACK/move and rebuild failures propagate to non-zero CLI exit. |
| Terra P3 global guard | KEPT / CONTROL ACCEPTED | `tests/TestCase.php:10` and `:31` remain unchanged this round. Control accepted the guard's scope across all tests because current Unit/Feature/Integration suites pass under it and it protects dev data. Current full-suite and explicit Integration passes are below. Prerequisites remain documented at `docs/database/nosql-local.md:265`. |

### Failing tests first: red -> green

Each command was a separate direct invocation; counts include the isolation and
teardown assertions. Implementation followed the failing test for each slice.

| Command | Red exit / result | Green exit / result |
|---|---|---|
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php --filter=test_fresh_entry_without_event_is_dead_lettered_once_with_original_id` | 2; 1 test, 9 assertions, undefined array key `event` | 0; 1 test / 17 assertions |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php --filter=test_read_group_error_is_distinct_from_an_empty_read` | 1; 1 test, 13 assertions, CLI incorrectly returned 0 instead of 1 | 0; 1 test / 16 assertions |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php --filter=test_retry_error_recording_failure_stays_pending_and_bounded` | 1; 1 test, 10 assertions, CLI incorrectly returned 0 instead of 1 | 0; 1 test / 45 assertions |

The first post-fix read regression run exited 1 (1 test / 16 assertions) because
the test read Artisan output twice; the second read was empty. Capturing it once
fixed the test harness; the real false-reply and CLI failure assertions passed.
Existing repeated projection-error retries also exercise successful HSET updates
returning 0; the validator deliberately accepts that legitimate result.

### Accepted limitations and Gate 6 follow-up

- Lua prevents interleaving but is not failure-atomic. Trending::APPLY,
  acknowledge, deadLetter and replay can retain earlier writes if a LATER command
  fails. Wrong-type keys are validated or hit before the relevant writes; the
  realistic remaining trigger is a per-command Redis ACL denial or a server fault
  mid-script. Consequences include dedupe/version markers without a score
  increment (lost increment on retry), a duplicate DLQ row, a double replay, or an
  ACKed-but-undeleted input entry. This local single-node proof runs without ACLs;
  these late-failure recovery cases were not injected or claimed resolved.
- Gate 6 follow-up: idempotent move markers / deterministic destination IDs,
  plus projection partial-write recovery. Reordering apply to score before marker
  trades lost increment for double increment; deleting/ACKing before destination
  creation trades duplication for loss. Moving XDEL before XACK can instead leave
  a pending entry without its payload. No pure reordering removes a listed class
  without a new failure mode, so no script ordering was changed.
- Source/staging failures before promotion and rejected RENAME preserve the old
  ranking. A network interruption around RENAME leaves its outcome uncertain:
  the new ranking may already be live despite CLI failure. Transport ambiguity
  was documented, not fault-injected. Earlier unconditional preservation claims
  are superseded by this qualification.
- RedisResult checks only the operations enumerated above. Group creation,
  publish, terminal HGET, inspection and top reads are not all guarded with
  command-specific validation. Handled apply errors enter retry/DLQ; the report
  does not claim every Redis error makes every CLI action fail.

### Final verification (separate invocations, actual process exits)

All mutating tests ran serially. Every flush used the existing live DB15 guard;
the read-race injection checks DB15 immediately before mutation. No FLUSHALL or
dev DB0/1/2 mutations. Composer's original process was awaited to completion.

| Command | Exit | Counts / result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` run 1 | 0 | 29 tests / 495 assertions |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` run 2 | 0 | 29 / 495 |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` run 3 | 0 | 29 / 495 |
| `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` | 0 | 3 / 42 |
| `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` | 0 | 4 / 47 |
| `composer run test` | 0 | 87 / 1010; Unit/Feature; config:clear succeeded |
| `vendor/bin/phpunit tests/Integration` | 0 | 10 / 72; Mongo/Neo4j/Cassandra; global guard unchanged |
| exact Pint invocation below | 0 | All 15 #15 PHP files passed |
| `git diff --check` | 0 | Clean |

```bash
vendor/bin/pint --test app/Interaction/InteractionEvent.php app/Interaction/InteractionReplaySource.php app/Interaction/SyntheticInteractionReplaySource.php app/Interaction/Trending.php app/Interaction/InteractionStream.php app/Interaction/RedisResult.php app/Console/Commands/Interactions.php app/Providers/AppServiceProvider.php config/database.php config/nosql.php tests/Concerns/GuardsRedisTestDatabase.php tests/Feature/RedisStreamsTest.php tests/Feature/RedisQueueTest.php tests/Feature/RedisSessionTest.php tests/TestCase.php
```

Round 2 edits only: `app/Interaction/InteractionStream.php`,
`tests/Feature/RedisStreamsTest.php`, `docs/database/nosql-local.md`, and this report.
Owner WIP preserved. No commits, stash, push, delegation, dependency/Compose/.env
changes or infrastructure redesign. PEL paging and UUID case fixes retained.

### Final PROPOSED ATOMIC COMMIT GROUPING

Control owns committing. Exact #15 paths only; exclude owner WIP and older task
packets. This supersedes earlier proposed groupings.

1. `fix(redis): isolate NoSQL DB2 and share guarded test connections` ->
   `config/database.php`, `config/nosql.php`, `tests/TestCase.php`,
   `tests/Concerns/GuardsRedisTestDatabase.php`,
   `tests/Feature/RedisQueueTest.php`, `tests/Feature/RedisSessionTest.php`.
2. `feat(interactions): add bounded streams and rebuildable idempotent trending` ->
   `app/Interaction/InteractionEvent.php`, `app/Interaction/InteractionStream.php`,
   `app/Interaction/Trending.php`, `app/Interaction/RedisResult.php`,
   `app/Interaction/InteractionReplaySource.php`,
   `app/Interaction/SyntheticInteractionReplaySource.php`,
   `app/Providers/AppServiceProvider.php`, `tests/Fixtures/interactions-v1.json`.
3. `feat(cli): expose interaction recovery and verify isolated Redis loss` ->
   `app/Console/Commands/Interactions.php`, `tests/Feature/RedisStreamsTest.php`.
4. `docs(redis): document Gate 5 recovery evidence and boundaries` ->
   `docs/database/nosql-local.md`, `.agent/tasks/redis-cutover/GATE-REPORT-15.md`.

## Independent review (gpt-6.1-terra, read-only, medium)

| Pass | Scope | Verdict | Findings |
|---|---|---|---|
| 1 | first implementation (16 files, sha256-pinned) | FAIL | P1 unchecked EVAL in Trending -> silent ACK/loss; P1 unchecked ACK/DLQ/replay/rebuild results; P2 PEL scan capped at 1000; P2 UUID case bypasses dedupe; P3 global TestCase guard |
| 2 | after review-fix round 1 (17 files) | FAIL | PEL paging + UUID case RESOLVED; P1 Lua scripts not failure-atomic after first write; P2 fresh entry without `event` crashes; P2 `xReadGroup` false / unchecked HSET |
| 3 | after review-fix round 2 (17 files) | **PASS_WITH_NOTES** | All P2 RESOLVED; no remaining P1/P2; Lua partial-write accepted as P3 limitation |

Control added one finding in round 1 (pending entry whose stream payload is gone crashed the consumer) — fixed.

Control ruling recorded for the owner: Terra's pass-2 P1 (Lua scripts are not rolled back if a later command fails) was
**downgraded to an accepted P3 limitation** rather than redesigned. Within each script the commands after the first write hit the
same key or a pre-validated key, so the realistic trigger is a per-command Redis ACL denial or a server fault mid-script; the local
Compose Redis runs without ACLs. Terra reviewed this ruling in pass 3 and found no additional realistic trigger in this environment.
It is documented in `docs/database/nosql-local.md` with a Gate 6 follow-up (idempotent move markers / deterministic destination ids).

## Control final gate (Fable 5.2, 2026-11-15)

Run after the last code change, no other worker active, each command its own invocation, exit code of the command itself.
Reviewed file set unchanged against the pass-3 sha256 manifest before and after the gate.

| Command | Exit | Result |
|---|---|---|
| `composer check-platform-reqs` | 0 | pass (PHP 8.4.27, ext-mongodb 2.5.2, ext-redis 6.3.0) |
| `./scripts/nosql-health.sh` (before / after) | 0 / 0 | 4/4 READY |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` x3 | 0, 0, 0 | 29 tests / 495 assertions each |
| `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` | 0 | 3 / 42 |
| `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` | 0 | 4 / 47 |
| `composer run test` | 0 | 87 / 1010 |
| Mongo / Neo4j / Cassandra integration | 0, 0, 0 | 3/19, 4/35, 3/18 |
| `vendor/bin/pint --test` on all #15 PHP files | 0 | passed |
| `git diff --check` | 0 | clean |

Isolation: no `bootstrap/cache/config.php`; Mongo `vietfeed_test`; SQLite `:memory:`; Redis DB15 with live `getDbNum()` guard before every flush.
Memory after gate: redis 11.2MiB/256MiB, cassandra 1.446GiB/1.5GiB, mongo 164.7MiB/768MiB, neo4j 789.4MiB/1GiB; no OOM, no restarts.

**Verdict: local tests PASS, independent review PASS_WITH_NOTES -> overall PASS_WITH_NOTES.**

Control notes beyond the worker's limitations:
- Only the final tree was gate-tested; the intermediate atomic commits were not tested individually.
- Terra never ran PHPUnit (read-only sandbox); runtime evidence is the worker's and control's runs.
- The owner's local `.env` sets `REDIS_NOSQL_DB=1`, which collides with the cache DB. The code default and `.env.example` are 2;
  the local `.env` was not changed and should be set to 2 before the machine uses Redis cache.
- Gate 5 after this PR: sessions/cache/queue on Redis proven in tests, stream retry/DLQ + trending demonstrable, flush/rebuild proven
  on an isolated DB. Still open: SQL queue drain per environment (UNVERIFIED), distributed lock (deferred), real replay source (Gate 6).
