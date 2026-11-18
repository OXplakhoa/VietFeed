# Gate 9.5 slice 3a — Bookmark and Boost on MongoDB

## Implementation

### Plan

Branch `cutover/interactions-mongo` at `1f17c1c`. Before edits, the owner WIP hash check passed and `composer run test` passed with 353 tests and 6,093 assertions on the isolated stores.

- Store active bookmarks and boosts in separate MongoDB collections. Each document has a string UUIDv7 `_id`, string `user_id` and `article_id`, BSON timestamps, and an optional integer `legacy_sql_id`. Create unique `{user_id, article_id}`, per-user recent `{user_id, created_at DESC}`, and per-article `{article_id}` indexes with an idempotent migration.
- Toggle with an insert or an exact-ID conditional delete. On a competing insert (duplicate-key error) or a lost delete, retry from the current state. This linearizes each successful request on one document write, without transactions. Count persisted active rows after the write.
- Publish through the existing stream once per successful write. Each membership incarnation has a UUIDv7 aggregate ID, with version 1 for add and 2 for remove. Use the document ID as the stable add event ID, and mint a UUIDv7 for removal. Log Redis publish errors with exception type only. Reemit surviving membership additions with the original ID and timestamp. Rebuild ranking from current MongoDB membership to recover missing removals as well.
- Write Cassandra rows to `activity_by_user_month` (partition `user_id, month`) and `activity_by_article_day` (partition `article_id, day`). UTC time buckets and clustering by `occurred_at DESC, event_id ASC` support recent history and window queries. Retry partial writes with the same primary keys via `StreamConsumer`, with the shared DLQ and replay.
- Score real boosts in Redis at five points per active membership. Retain version markers per incarnation in the ranking key so out-of-order and repeated delivery converge. Check the current incarnation when applying an add so an old pending add cannot resurrect a removed membership after rebuild. Keep the explicit synthetic demo rebuild. Feed the home trending sidebar from Redis with Mongo fallback and canonical counts, preserving the 72-hour eligibility and other feed sections. Schedule the existing consumer for normal operation.
- Use one Mongo group per interaction collection per count page, including bookmarked cards, detail and related cards, admin sorting, dashboard, and admin users. Preserve sorting, pagination and rendering contracts.
- Import ordered SQL chunks by natural key without overwriting Mongo documents. Preserve legacy IDs and timestamps, report inserted/existing counts, and optionally publish flagged backfill events for newly imported rows. Keep legacy tables for import and use the fresh seed path for development.
- Planned PHP changes cover `app/Models/{Bookmark,Boost,Article,User}.php`; `app/Models/InteractionMembership.php`; `app/Content/ArticleCounts.php`; `app/Http/Controllers/{Article,Bookmark,Boost,Home}Controller.php`; `app/Http/Controllers/Admin/{Article,Dashboard,User}Controller.php`; `app/Interaction/{InteractionEvent,InteractionStream,Trending,InteractionToggle,InteractionActivity,MongoInteractionReplaySource,InteractionSqlImport}.php`; `app/Console/Commands/{Interactions,ImportSqlInteractions}.php`; new Mongo index migration; `routes/console.php`; `tests/TestCase.php`; `tests/Feature/{CatalogArticlePagesTest,InteractionsMongoTest,ApiTokensTest,BoostTest}.php`. Documentation changes cover `docs/database/nosql-local.md` and this report.
- Run focused tests after the state/count change and after projection wiring. Cover web/API policy and responses, competing writes, exactly one event per write, actual Cassandra and Redis delivery, replay/outage recovery, trending rebuild, batch counts, and rerunnable SQL import. Run changed suites three times, then the full suite, platform requirements, touched-file Pint, SQL-reader scan, WIP hashes and final status.

### Decisions and implementation

This section records the initial submission. The REVIEW-1 fix round below updates the importer and ranking behavior.

| Outcome | Result |
|---|---|
| 1. Current state | Both MongoDB collections use UUIDv7 membership IDs and a unique user/article index. A toggle retries on error 11000 or a lost exact-ID delete. Response counts are direct MongoDB counts. Existing web validation, web sanction checks and API bearer/controller policy apply. |
| 2. Publication | Each successful toggle attempts one Redis XADD after the MongoDB write. Add identity and occurrence time come from the document, allowing `reemit` to recover the same event. Removal gets a new event ID. Publish failure logs exception type and the MongoDB action completes. |
| 3. Consumers | The existing stream consumer writes both Cassandra partitions before ACK. It also applies real boosts and unboosts with per-membership version checks in Redis. The home sidebar uses the projection with grouped MongoDB fallback. Default rebuild stages a projection from active MongoDB boosts and promotes it after completion. Synthetic rebuild remains an explicit demo option. |
| 4. Counts | Home, article/detail/related cards, bookmark list, category/search, admin source/articles, dashboard and user list batch MongoDB counts per collection. Admin count sorting reuses its full grouped count for the sorted column on the displayed page. Dashboard totals reuse the same grouping as its ranking. |
| 5. Import | `interactions:import-sql` walks both SQL tables in ID order in chunks of 500, preserves available source timestamps and `legacy_sql_id`, and matches the natural key. If both source timestamps are NULL, MongoDB records import time for recovery. A rerun leaves existing documents intact. `--emit` flags new import events as backfill and reports publish failures. |
| 6. Tests | See the test edits and verification table below. The live API bearer contract still returns 401 for a revoked family. The API action's 403 is covered with the bearer identity injected to simulate a sanction becoming visible at action time. |
| 7. Docs | The local database guide covers document and stream shapes, Cassandra partitions, native queries, rollout, and recovery. |

The Cassandra projections use `(user_id, year_month)` and `(article_id, activity_day)` partitions. Both cluster on `occurred_at DESC, event_id ASC`. UTC buckets keep the user query bounded by month and article query by day. Repeated delivery of an immutable event overwrites the same row in both tables.

The Redis membership version is scoped to a single add/remove pair. A version-2 delivery received first adds no points and prevents an older version-1 delivery from inflating the ranking. An add whose exact membership is already gone also projects the removed state. That check covers old queued additions after a rebuild, even when the corresponding removal never reached Redis. The home sidebar still filters the same 72-hour candidates, breaks score ties by publication time, and shows canonical MongoDB counts.

### Test edits

| File | Edit and reason |
|---|---|
| `tests/TestCase.php` | Verify the live nosql connection selected DB15 before feature tests can publish or read ranking. Delete MongoDB bookmark and boost fixtures at teardown because SQLite rollback does not cover them. |
| `tests/Feature/CatalogArticlePagesTest.php` | Replace SQL chunk-count assertions with one MongoDB aggregation per collection and zero SQL queries. Retain the ranking fixture, ordering, ties, zero-count, hydration and pagination assertions. |
| `tests/Feature/ApiTokensTest.php` | Keep existing real bearer and response assertions, verify the actual Redis add/remove envelopes, and cover the action-time temp-ban 403. |
| `tests/Feature/BoostTest.php` | Point the existing database presence/absence assertions at the MongoDB model connection. Web and home assertions are unchanged. |
| `tests/Feature/InteractionsMongoTest.php` | Ten feature tests cover Mongo indexes and BSON types, web responses/sanctions, deterministic write interleavings, one event per change, Redis publish failure and reemit, lost removal and rebuild, live Cassandra/Redis retry and DLQ replay, real home ranking and stale delivery, SQL import reruns, and batched count/SQL isolation across runtime pages. |

### Files changed

- Models include `app/Models/{Article,User,Bookmark,Boost,InteractionMembership}.php`.
- Controllers include `app/Http/Controllers/{Article,Bookmark,Boost,Home}Controller.php` and `app/Http/Controllers/Admin/{Article,Dashboard,User}Controller.php`.
- Counts use `app/Content/ArticleCounts.php`, using the existing `app/Support/MongoCounts.php`.
- Interaction code includes `app/Interaction/{InteractionEvent,InteractionStream,Trending,InteractionActivity,InteractionToggle,InteractionSqlImport,MongoInteractionReplaySource}.php`.
- Commands and scheduling use `app/Console/Commands/{Interactions,ImportSqlInteractions}.php` and `routes/console.php`.
- Schema indexes are in `database/migrations/2026_11_18_210001_create_mongodb_interaction_indexes.php`.
- Tests include the five files in the table above.
- Documentation includes `docs/database/nosql-local.md` and this report.

### Cutover order

1. Choose the fresh development seed/re-ingest path or the legacy import path. Take a read-only SQL backup for the legacy path.
2. Quiesce interaction writes and pause the interaction consumer. Deploy the slice, apply the new MongoDB indexes with `php artisan migrate --force`, then run `php artisan interactions schema`.
3. On the fresh development path, seed and re-ingest. On the legacy path, run `php artisan interactions:import-sql`, optionally with `--emit`. Review its per-table totals. Recover any missed active additions with `php artisan interactions reemit` after Redis is healthy.
4. Run `php artisan interactions rebuild` while writers and consumers are paused. Resume traffic and the scheduler, then inspect the stream, DLQ and top scores. Keep the SQL tables through the cutover window.

### Known limitations in the initial submission

- There is no transaction spanning MongoDB and Redis. A process interruption or failed XADD can leave an activity gap. Reemit repairs active additions. Rebuild repairs the ranking from current membership, including lost removals. Cassandra history for a lost removal or an already-removed addition can only be recovered if the original event is available.
- Redis ranking is eventual. Cassandra projection failures delay ranking while delivery retries. Ranking scores and metadata can be evicted together, and the metadata grows with processed boost incarnations until rebuild. Pause interaction writes and consumers for consistent rebuilds. The synthetic demo replaces the same ranking, so use the default rebuild afterwards to restore the live projection.
- Home, dashboard, and count-sorted admin pages still group current interactions over the relevant collections or scan candidate IDs. The slice adds no cached authoritative counters. The tested local Cassandra schema uses the existing single-node replication pattern.
- SQL import was rehearsed with isolated SQLite and MongoDB. Fresh seeding, legacy import, and operational rollout against development or production data were outside this execution.

### Verification

All test suites ran sequentially. Commands redirected both streams to the stated `/tmp/g95s3a-*.log` and captured the original exit status. Live stores were MongoDB `vietfeed_test`, Redis DB15, Cassandra `vietfeed_test`, and SQLite `:memory:`.

| Command | Exit | Result |
|---|---:|---|
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` before work | 0 | All six manifest paths verified (`g95s3a-wip-before.log`). |
| `composer run test` baseline | 0 | 353 tests, 6,093 assertions (`g95s3a-baseline.log`). |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php tests/Feature/ModerationEnforcementTest.php tests/Feature/AdminArticleDefectsTest.php` | 0 | MongoDB state and counts, 48 tests and 668 assertions (`g95s3a-step1.log`). |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php tests/Feature/CatalogArticlePagesTest.php tests/Feature/ModerationEnforcementTest.php tests/Feature/ApiTokensTest.php` | 0 | First projected-event run, 129 tests and 2,578 assertions (`g95s3a-step2.log`). |
| `vendor/bin/phpunit tests/Feature/InteractionsMongoTest.php` and then the same command with `tests/Feature/ApiTokensTest.php` | 1, 1 | Corrected a fixture's expected history to include both delivered boosts and changed the dashboard URL to `/admin/dashboard` (`g95s3a-step3.log`, `g95s3a-step4.log`). |
| `vendor/bin/phpunit tests/Feature/InteractionsMongoTest.php tests/Feature/ApiTokensTest.php tests/Feature/CatalogArticlePagesTest.php tests/Feature/RedisStreamsTest.php` | 0 | 124 tests, 2,654 assertions (`g95s3a-step5.log`). |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php tests/Feature/CatalogArticlePagesTest.php tests/Feature/InteractionsMongoTest.php` ×3 | 0, 0, 0 | 95 tests, 2,132 assertions each (`g95s3a-changed-{1,2,3}.log`). |
| `composer run test` first full cutover attempt | 1 | 361 of 362 passed. Corrected the old SQLite assertion in `BoostTest` (`g95s3a-first-full.log`). |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php tests/Feature/BoostTest.php tests/Feature/CatalogArticlePagesTest.php tests/Feature/InteractionsMongoTest.php` ×3 | 0, 0, 0 | 99 tests, 2,177 assertions each (`g95s3a-final-changed-{1,2,3}.log`). |
| `composer check-platform-reqs` | 0 | All requirements satisfied (`g95s3a-platform.log`). |
| `xargs -r vendor/bin/pint < /tmp/g95s3a-touched-php.txt` and `xargs -r vendor/bin/pint --test < /tmp/g95s3a-touched-php.txt` | 0, 0 | Touched PHP paths only (`g95s3a-pint.log`, `g95s3a-pint-test.log`). |
| `rg -n -i -f /tmp/g95s3a-sql-reader.regex app routes resources database/seeders` | 0 | The only hits are `InteractionSqlImport.php:18–19`. The SQL query log check also confirms runtime pages ignore deliberately populated SQL rows (`g95s3a-sql-proof.log`). |
| `composer run test` ×3 after fixes | 0, 0, 0 | **363 tests, 6,715 assertions** each. Covers the shared `TestCase.php` edit three times (`g95s3a-final-full-{1,2,3}.log`). |
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` after work and `cmp -s` of the before and after logs | 0, 0 | All six manifest hashes unchanged (`g95s3a-wip-after.log`). |
| `git diff --check` | 0 | Clean (`g95s3a-diff-check.log`). |
| `git status --short` and baseline status comparison | 0, 0 | All prior entries preserved. The new status entries match the touched PHP paths and database guide. The report is under the already-untracked task directory (`g95s3a-status-after.log`). |

SQL scan pattern used with `rg -f` follows.

```regex
(table[[:space:]]*\([[:space:]]*['"]|exists:|from[[:space:]]+["`]?|join[[:space:]]+["`]?)\b(bookmarks|boosts)\b
```

The slice is left in the working tree. All writes exercised by verification used the isolated test stores. No container lifecycle operations or Git staging operations were used.

**STATUS: READY_FOR_REVIEW**

## Fix round (REVIEW-1)

Independent review returned **PASS_WITH_NOTES**. The control decisions for MINOR-1 through MINOR-3 were implemented on the same branch.

| Decision | Change and evidence |
|---|---|
| F1 | `interactions:import-sql` checks both MongoDB collections before copying. If either contains a document with `legacy_sql_id`, the command refuses with exit 1 and tells the operator to run once, in the cutover window. `--force` permits a deliberate resume. Tests cover refusal without emission, a forced no-op rerun, and a removed bookmark that stays removed when the only remaining legacy marker is on a boost. A forced rerun can restore removed state, so reserve it for paused cutover traffic. |
| F2 | Unboost removes the version marker and the previously applied contribution. An add for a removed MongoDB incarnation scores zero. Live ranking updates and rebuilds share a Redis lock, with an owner check in Lua to reject writes after lock loss. Home distrusts a sorted set without `meta:ready`, ranks by MongoDB counts for that request, and attempts a lazy rebuild. Staging eviction aborts promotion. The web routes share a per-user limit of 30 toggles per minute. The 429 supplies a Vietnamese JSON message and `Retry-After` for the existing JS. Tests cover marker removal, late delivery, partial ranking after eviction, restored scores, and the shared rate limit across both routes and separate users. |
| F3 | Synthetic fixture events and `interactions rebuild --synthetic` target `interactions:trending:demo:v1`. `interactions top --synthetic` reads that key. A command test checks the demo output and proves that live scores survive. Gate 5 tests use the demo key and retain their Redis failure, replay, and promotion assertions. |
| MINOR-4 and NITs | Documented that a lost removal requires `interactions rebuild` to repair ranking. `DemoEngagementSeeder` writes memberships without events, so run `interactions rebuild` after seeding. Admin count sorting and dashboard aggregation are O(collection). |

The browser acceptance review also found pre-existing article UI behavior. The server-rendered “lượt lưu” and header “Boost” counts update on page reload. After a bookmark toggle the button label stays “Lưu bài” and the icon changes. JS and views match main for this behavior.

Round files include `app/Console/Commands/{ImportSqlInteractions,Interactions}.php`, `app/Http/Controllers/HomeController.php`, `app/Interaction/{InteractionSqlImport,Trending}.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, `tests/Feature/{InteractionsMongoTest,RedisStreamsTest}.php`, the local database guide, and this report.

### Fix-round verification

The suites ran sequentially on the same isolated stores as the initial implementation. Commands redirected both streams to the noted files and recorded the original exit status.

| Command | Exit | Result |
|---|---:|---|
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` before work | 0 | Six manifest paths verified (`g95s3a-r1-wip-before.log`). |
| `vendor/bin/phpunit tests/Feature/InteractionsMongoTest.php tests/Feature/RedisStreamsTest.php` | 0 | Initial focused run, 43 tests and 926 assertions (`g95s3a-r1-focused.log`). |
| Same two test files ×3 after staging-eviction coverage | 0, 0, 0 | 44 tests and 938 assertions each (`g95s3a-r1-tests-{1,2,3}.log`). |
| `composer run test` before deferring limiter registration | 1 | 367 of 368 passed. The API rate-limit subprocess guard exited 11 before reading a counter because early provider registration resolved the Redis manager before the DB15 override. Registration now runs when the rate limiter is first resolved (`g95s3a-r1-full-initial.log`). |
| `vendor/bin/phpunit tests/Feature/InteractionsMongoTest.php tests/Feature/RedisStreamsTest.php tests/Feature/ApiTokensTest.php` ×3 | 0, 0, 0 | 114 tests and 2,468 assertions each, including the API subprocess regression check (`g95s3a-r1-final-tests-{1,2,3}.log`). |
| `xargs -r vendor/bin/pint < /tmp/g95s3a-r1-touched-php.txt` and `xargs -r vendor/bin/pint --test < /tmp/g95s3a-r1-touched-php.txt` | 0, 0 | Nine touched PHP paths (`g95s3a-r1-pint.log`, `g95s3a-r1-pint-test.log`). |
| `git diff --exit-code 1f17c1c -- resources/js/app.js resources/views/articles/show.blade.php` | 0 | The article JS and Blade view match main (`g95s3a-r1-ui-proof.log`). |
| `composer run test` after the limiter fix | 0 | **368 tests and 6,831 assertions** (`g95s3a-r1-full.log`). |
| `sha256sum -c /home/dolakhoa/.vietfeed-control/wip.sha256` and `cmp -s` of the before and after hash logs | 0, 0 | All six owner WIP manifest paths unchanged (`g95s3a-r1-wip-after.log`). |
| `git status --short` and worktree status comparison | 0, 0 | Prior entries preserved. Added status entries are `app/Providers/AppServiceProvider.php`, `routes/web.php`, and `tests/Feature/RedisStreamsTest.php` (`g95s3a-r1-status-after.log`). |
| `git diff --check` | 0 | Clean (`g95s3a-r1-diff-check.log`). |

**STATUS: READY_FOR_REVIEW**

## Control acceptance (2026-11-18, Claude Opus 5.5 control)

Workflow: implementation GPT 6.2 Sol; independent reviews Claude Opus — REVIEW-1.md PASS_WITH_NOTES, three MINORs fixed;
REVIEW-2.md (fix round) PASS_WITH_NOTES. Browser acceptance GPT 6.1 Terra.

| Check | Result |
|---|---|
| `composer run test` (control, after fix round) | exit 0 — 368 tests / 6831 assertions (main 1f17c1c: 353 / 6093) |
| `pint --test` touched PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| SQL reads of bookmarks/boosts | only `App\Interaction\InteractionSqlImport` |
| Owner WIP sha256 | unchanged |

Browser (Terra, before the fix round; isolated Mongo/Redis DB15/Cassandra `vietfeed_test`): bookmark/boost persist and reload with
correct counts (article 1 boost count 2 after U2); rapid double-click left no duplicate; bookmarks page exact; consumer 9 deliveries,
0 pending, 0 DLQ; Cassandra user/article activity with all four event types; trending ranks article 1 (10) above article 2; temp-banned
user gets 403 on bookmark/boost with no Mongo change; admin dashboard/articles/users 200. Terra marked steps 1–2 FAIL for the article
header counts not updating live and the bookmark label staying "Lưu bài": control verified resources/js/app.js and the article view are
unchanged from main — pre-existing UI behaviour, not a regression (recorded as a UX follow-up). The fix round only touched import guard,
trending projection/home ranking fallback, toggle throttle and the demo key.
