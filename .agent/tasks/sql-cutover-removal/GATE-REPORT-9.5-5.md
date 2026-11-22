# Gate 9.5 slice 5 (final) — Remove the SQL runtime and SQLite from tests

## Implementation

### Plan

Branch `cutover/sql-removal`, base `main` `fbddbf7` (tag `pre-sql-removal`). Owner WIP hashes passed before edits
(`sha256sum -c ~/.vietfeed-control/wip.sha256`: 6 × OK). Baseline `composer run test` exited 0 with **395 tests / 7,960
assertions** (34 s).

Installed versions checked before relying on features: `laravel/framework` v13.11.2, `mongodb/laravel-mongodb` 5.8.2.
- 5.8.2 ships **no** dedicated Mongo migrations repository or failed-job provider class. Laravel's own
  `DatabaseMigrationRepository` and `DatabaseUuidFailedJobProvider` only use the query/schema builder (`table()->insert`,
  `orderBy`, `pluck`, `max`, `where()->delete`, `Schema::hasTable/create`), which laravel-mongodb implements on its
  `mongodb` connection (`Schema\Builder::hasTable` = `hasCollection`, `create` = create collection, `id` alias of `_id`).
  Both therefore run unchanged on the `mongodb` connection; verified live (migrate / migrate:status / queue:failed /
  queue:retry, tests below).
- 5.8.2 ships `MongoDBBusServiceProvider` (auto-discovered) with `MongoBatchRepository`, selected by
  `queue.batching.driver = mongodb`.
- Laravel 13 `PasswordBrokerManager` supports `'driver' => 'cache'` (`CacheTokenRepository`: hashed token under
  `sha256(email)` with `put(..., expire*60)` TTL; `recentlyCreatedToken` keeps the throttle).
- Laravel merges its framework defaults into `database.connections`, `cache.stores` and `queue.connections`
  (`LoadConfiguration::mergeableOptions`), so deleting SQL entries from our config files is not enough: the framework's
  sqlite/mysql/... connections, `database` cache store and `database` queue connection come back. Dropping them in
  `AppServiceProvider::register()` was chosen over `dontMergeFrameworkConfiguration()` (that would also drop every
  unpublished framework config file).

Inventory of SQL touch points (grep of app/, config/, routes/, database/, tests/, bootstrap/, scripts/, composer.json,
phpunit.xml) and their replacement:

| Touch point | Replacement |
|---|---|
| `config/database.php` default `env('DB_CONNECTION','sqlite')`; sqlite/mysql/mariadb/pgsql/sqlsrv; `Pdo\Mysql` import | default **`'mongodb'` (not env-driven)**; only the `mongodb` connection; framework-merged SQL connections dropped in `AppServiceProvider` |
| Migrations repository on the default SQL connection | Laravel repository on the default `mongodb` connection → Mongo collection `migrations` |
| 22 SQL migrations (`0001_*`, `2026_05_*`, `2026_06_*`, repair and two widen migrations) | deleted; 8 Mongo index migrations kept **unchanged** (all idempotent) + new `2026_11_18_300001_create_mongodb_queue_indexes` |
| `queue.failed` `database-uuids` on `DB_CONNECTION` | `database-uuids` on `mongodb`, collection `failed_jobs` (unique `uuid_1`, `failed_at_1`) |
| `queue.batching` SQL `job_batches` | `driver mongodb`, collection `job_batches` (`created_at_1`); the app uses no batches |
| `queue.default` fallback `database`; `database` connection; failover → database | `redis`; connection removed (also the merged one); failover `redis → deferred` |
| `cache.default` fallback `database`; `database` store; failover → database | `redis`; store removed (also the merged one); failover `redis → array` |
| `session.driver` fallback `database` | `redis` |
| `app.maintenance.store` fallback `database` | `redis` |
| `auth.passwords.users` table `password_reset_tokens` | `driver cache`, store `env('AUTH_PASSWORD_RESET_STORE','redis')`, expire 60, throttle 60 |
| Six importers + commands; `ProcessingController` `SqlImport::unimported()`; Blade legacy warnings | deleted |
| `SourceFetchHistory::IMPORTS` Cassandra marker table (written only by the history importer) | constant + `CREATE TABLE` removed; existing tables are left alone |
| `StoryPipeline::assertSafeTestStores()` required `database.default === sqlite` / `:memory:` | requires `mongodb` default and `['mongodb']` as the only connection |
| `composer.json` `post-create-project-cmd` touches `database/database.sqlite` | removed |
| `scripts/smoke-mysql-path.sh` (SQL session/cache/queue smoke) | deleted |
| `phpunit.xml` `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` | removed |
| `tests/TestCase.php` sqlite asserts; `RefreshDatabase` in 30 test classes; SQL fixtures and SQL query-log assertions | see "Tests" below |

### Decisions and outcomes

| Outcome | Implementation |
|---|---|
| 1. No SQL runtime | Default connection hard-wired to `mongodb` and it is the only configured connection, so no `.env` value can select a SQL driver (an old `DB_CONNECTION=sqlite` is ignored instead of breaking the boot). Migrations run on Mongo (`migrations` collection). Proven: whole suite and the clean-reset proof (migrate, schemas, seeders, HTTP pages, commands) pass with **no PDO SQL driver loaded**. |
| 2. Tokens / failed jobs / batches / defaults | Reset tokens in **Redis** (feature map §Users: "Redis chỉ giữ session, rate-limit và reset token có TTL"), cache store `redis` → Redis connection `cache` (DB1 in dev), TTL 60 min, 60 s throttle, existing Breeze controllers untouched. Failed jobs: Mongo `failed_jobs` via the stock `database-uuids` provider, `queue:failed`/`queue:retry` proven. Batches: Mongo-backed (unused). Session/cache/queue default to Redis in config and `.env.example`. No new dependency. |
| 3. Importers removed | 6 importer classes, 6 commands, processing-page warnings and their tests deleted; `docs/database/nosql-local.md` + this report point to tag `pre-sql-removal`. |
| 4. Tests | `phpunit.xml` without SQL; no `RefreshDatabase`; Mongo/Redis/Cassandra isolation; SQL assertions rewritten; suite-wide no-SQL guard (proven to fail). 395 → 379 (16 importer/SQL-migration tests deleted) + 5 new = **384**. |
| 5. Composer | No SQL-only package is required; `composer check-platform-reqs` exit 0 and lists no `pdo_*`; the only PDO mention in the lock file is `laravel/framework` `suggest: ext-pdo`. |
| 6. Clean reset | Proven twice from empty isolated stores in a tree copy without `.env`/`database.sqlite`, `DB_CONNECTION` unset; second run with no PDO SQL driver (below). |
| 7. Docs | `docs/database/nosql-local.md` (new slice 5 section with setup/runbook, test isolation, legacy-data path; historical sections flagged), `docs/demo/moderation-demo.md`, `.env.example`. `CLAUDE.md` not touched (owner WIP). |

Tests — isolation without RefreshDatabase/SQLite (`tests/TestCase.php`):
- `createApplication()` asserts `vietfeed_test`, `database.default === mongodb` and `['mongodb']` as the only connection
  (before any store is touched), then pins Redis default/cache/nosql to DB15 as before.
- Once per PHPUnit process `setUp()` drops the `vietfeed_test` `migrations` collection, runs `migrate` (all Mongo index
  migrations again, like the former `RefreshDatabase` run did) and wipes the test collections. Collections are not
  dropped: creating every index from nothing costs ~21 s on this WSL host (measured), against <1 s for the idempotent rerun.
- `tearDown()` wipes the Mongo test collections (now also `failed_jobs`, `job_batches`) and the Redis DB15 streams as before.
- **No-SQL guard:** a `QueryExecuted` listener records queries on any non-Mongo connection, and teardown also lists every
  resolved non-Mongo connection in the `DatabaseManager`; the violations are asserted after the wipe (so a failure never
  leaks state). `tests/Feature/NoSqlRuntimeTest.php` tests the guard itself with a PDO-less probe connection.

### Deleted tests (16) and reasons

| File | Deleted test | Reason |
|---|---|---|
| CommentsMongoTest | `test_comment_import_copies_parents_first_remaps_moderation_references_and_is_idempotent` | `comments:import-sql` removed |
| CommentsMongoTest | `test_comment_import_merges_int_and_string_keyed_duplicate_cases_and_converges` | same |
| CommentsMongoTest | `test_comments_hidden_by_an_unimported_legacy_sanction_are_counted_and_warned` | import + processing-page warning removed |
| CommentsMongoTest | `test_comment_import_finishes_an_interrupted_thread_copy` | import removed |
| ConfigMongoTest | `test_sql_import_is_rerunnable_and_remaps_references_without_changing_snapshots` | `config:import-sql` removed |
| ConfigMongoTest | `test_sql_import_allocates_category_uuid_and_preserves_disabled_state` | same |
| ConfigMongoTest | `test_import_sql_integer_flags_become_queryable_bson_bools` | same |
| ConfigMongoTest | `test_import_duplicate_natural_keys_abort_before_any_write_and_only_list_ids` | same |
| InteractionsMongoTest | `test_import_guard_stops_resurrection_when_only_a_legacy_boost_remains` | `interactions:import-sql` removed |
| InteractionsMongoTest | `test_import_preserves_existing_membership_and_emits_only_new_backfill_events` | same |
| InteractionsMongoTest | `test_import_without_emit_can_recover_legacy_rows_with_null_timestamps` | same |
| ModerationNosqlTest | `test_sql_import_is_idempotent_and_builds_cases_links_and_queue` | `moderation:import-sql` removed |
| ModerationNosqlTest | `test_sql_import_merges_legacy_reports_into_an_existing_live_case` | same |
| ModerationEnforcementTest | `test_repair_migration_is_rerunnable_and_cleans_legacy_reviewed_rows` | SQL migration `moderation_report_repair` deleted |
| ReadingPassNosqlTest | `test_import_copies_once_by_natural_key_and_invalidates_windows` | `unlocks:import-sql` removed |
| SourceFetchHistoryTest | `test_sql_import_maps_both_reference_shapes_and_requires_force_to_repair` | `sources:import-history-sql` removed |

### Rewritten tests (kept, store changed)

| Test | Change |
|---|---|
| All 30 classes using `RefreshDatabase` | trait removed (TestCase isolation) |
| CommentsMongoTest `test_only_the_import_classes_read_sql_comments` → `test_no_code_reads_sql_comments` | source scan expects no hit; runtime part asserts no SQL statement and only the `mongodb` connection resolved (the `DB::select('select 1')` probe is gone); two `DB::table('comments')->count()` checks dropped/replaced by `Comment::count()` |
| ReadingPassNosqlTest `test_only_the_import_reads_sql_article_unlocks` → `test_no_code_reads_sql_article_unlocks` | same pattern, legacy SQL row insert removed |
| ReadingPassNosqlTest `..._imported_unlocks_are_counted` → `..._out_of_band_ledger_rows_are_counted` | the granted ledger row is written directly into Mongo (origin `seed`) instead of via the import; same assertions |
| ReadingPassNosqlTest workers, StripeBillingTest worker, ApiTokensTest worker | subprocess env drops `DB_CONNECTION/DB_DATABASE/DB_URL`; guard checks `mongodb` default + only connection |
| ModerationNosqlTest processing-page test → `test_processing_page_moderation_section_survives_a_story_overview_failure` | legacy warning assertions replaced by "warning/`import-sql` absent"; outage behaviour kept |
| InteractionsMongoTest `..._ignore_legacy_rows` → `test_admin_counts_group_mongo_once_on_application_routes` | legacy SQL rows and SQL query log removed; Mongo grouping assertions kept |
| ConfigMongoTest `..._are_batched` → `..._config_reads_are_batched` | the SQL query count (always 0 now) is replaced by Mongo evidence: config collections are read only by list filters or `$in` batches (never an `_id` lookup per article) and counts are equal for 2 vs 8 articles on every URL except `/` (the homepage adds one `$in` batch per filled section: 9 → 14 reads, bounded by sections, not articles) |
| ConfigMongoTest health test, SourceFetchHistoryTest, CatalogArticlePagesTest | query logs taken explicitly on `mongodb`; SQL halves of the measurements removed (`[0, 9]` → `9`) |
| StripeBillingTest `test_webhook_and_entitlement_have_no_sql_or_redis_dependency` | `Schema::hasTable(...)` (would now ask Mongo) → assert `mongodb` is the only connection |
| RedisQueueTest | teardown `DB::table('jobs')` removed; failed-job check via `app('queue.failer')->all()` (Mongo) |
| New `tests/Feature/NoSqlRuntimeTest.php` (5 tests) | config has no SQL anywhere and `DB::connection('sqlite')` throws; Mongo migrations repository; Redis reset tokens (TTL ≤ 3600 > 3500, throttle, reset, single use); real failing job on the Redis worker → Mongo `failed_jobs` → `queue:failed` → `queue:retry`; guard self-test |

### Files changed

- Deleted: `app/Console/Commands/{ImportSqlComments,ImportSqlConfig,ImportSqlInteractions,ImportSqlSourceHistory,ImportSqlUnlocks,ModerationImportSql}.php`,
  `app/Content/{CommentSqlImport,ConfigSqlImport}.php`, `app/Interaction/InteractionSqlImport.php`, `app/Moderation/SqlImport.php`,
  `app/ReadingPass/ReadingPassSqlImport.php`, `app/SourceHistory/SourceHistorySqlImport.php`, 22 SQL migrations, `scripts/smoke-mysql-path.sh`.
- App/config: `app/Http/Controllers/Admin/ProcessingController.php`, `resources/views/admin/processing.blade.php`,
  `app/Providers/AppServiceProvider.php`, `app/StoryProcessing/StoryPipeline.php`, `app/SourceHistory/SourceFetchHistory.php`,
  `app/Moderation/ModerationEvents.php` (doc comments), `config/{database,queue,cache,session,auth,app}.php`, `composer.json`,
  `phpunit.xml`, `.env.example`, new `database/migrations/2026_11_18_300001_create_mongodb_queue_indexes.php`.
- Tests: `tests/TestCase.php`, `tests/Concerns/SourceHistoryFixtures.php`, 35 test classes edited (list in `git status`), new
  `tests/Feature/NoSqlRuntimeTest.php`.
- Docs: `docs/database/nosql-local.md`, `docs/demo/moderation-demo.md`.
- Not touched: owner WIP (`CLAUDE.md`, `database/seeders/SourceSeeder.php`, STATUS files, `pi-warden.md`, `.pi/`, `docs/agents/`,
  `.agent/control/`), `docker-compose.yml`, active `.env`, `database/database.sqlite` (left in place; no longer read).

### Verification

| # | Command | Result |
|---|---|---|
| baseline | `composer run test` (before edits) | exit 0 — 395 tests, 7,960 assertions |
| 1 | `composer run test` × 3 (final tree) | exit 0 / 0 / 0 — **384 tests, 8,450 assertions** each (35–36 s) |
| 1b | `PHP_INI_SCAN_DIR=<conf.d without pdo_mysql, pdo_sqlite, sqlite3, mysqli, mysqlnd> composer run test` (`PDO::getAvailableDrivers()` = empty) | exit 0 — 384 tests |
| 1c | `vendor/bin/phpunit tests/Integration/{MongoConnectionTest,MongoAuthDoubleTest,StoryPageProjectionsTest}.php` | exit 0 / 0 / 0 (2, 1, 2 tests) |
| 2 | Guard proof: temporarily added to `ExampleTest` `config([...sqlite :memory:]); DB::connection('sqlite')->select('select 1');` then `composer run test` | exit 1 — 383 passed, 1 failed: `A SQL database connection or query was used during the test. … 'sqlite: select 1', 'connection [sqlite] (sqlite) was resolved'`; file restored from backup |
| 3 | `composer check-platform-reqs` | exit 0 — ext-mongodb, ext-redis, …; no `pdo_*` listed |
| 3b | `vendor/bin/pint --test <51 touched PHP files>` (after `pint` on the same files) | exit 0 |
| 4 | `grep -rnE "DB::table\|DB::select\|DB::statement\|DB::connection\(\)\|sqlite\|mysql\|mariadb\|pgsql\|sqlsrv\|RefreshDatabase\|DB_CONNECTION" app config routes tests` | only intentional leftovers: two explanatory comments (`AppServiceProvider`, `config/database.php`), one TestCase comment, three source-scan regexes that assert *absence* of `DB::table` (Comments/Config/ReadingPass tests), and `NoSqlRuntimeTest` asserting `DB::connection('sqlite')` throws. `routes/`: none. |
| 5 | Clean-reset proof (`scratchpad/bin/reset-proof.sh`, below) | exit 0, `fail=0`, twice; second run with no PDO SQL driver in CLI and in the `artisan serve` process |
| 6 | `sha256sum -c ~/.vietfeed-control/wip.sha256` (before and after) | 6 × OK both times |
| 7 | `php artisan list --raw` (test env) | no `*import-sql*` command |

Clean-reset proof (outcome 6). Tree copied with `rsync` excluding `.git`, `node_modules`, `.env`, `database/*.sqlite*`,
`bootstrap/cache/*.php`; store credentials/ports sourced (never printed) from the active `.env`; `DB_CONNECTION`,
`DB_DATABASE`, `DB_URL`, `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` unset (config defaults used); `APP_ENV=testing`,
random `APP_KEY`, `MONGO_DB=vietfeed_test`, `CASSANDRA_KEYSPACE=vietfeed_test`, `REDIS_DB=REDIS_CACHE_DB=REDIS_NOSQL_DB=15`,
`REDIS_URL=''`, `MAIL_MAILER=array`.

```text
preconditions: .env present: no; database/database.sqlite present: no; DB_CONNECTION unset; pdo drivers loaded: (none)
empty stores (guarded script): mongo vietfeed_test collections after drop: 0; redis DB15 keys after flushdb: 0;
                               cassandra keyspace vietfeed_test dropped                                      exit=0
php artisan migrate --force               9 Mongo migrations DONE (recorded in Mongo `migrations`)            exit=0
php artisan migrate:status                9 × [1] Ran                                                         exit=0
php artisan storylens schema | interactions schema | reading-pass schema | moderation:audit schema
          | sources:history-manage bootstrap                                                          exit=0 each
php artisan db:seed --force               Category, Source (owner WIP version), User, DemoEngagement DONE   exit=0
php artisan storylens ingest              Ingested 10 Articles                                                exit=0
php artisan queue:failed                  No failed jobs found (Mongo provider)                               exit=0
php artisan serve (PHP_INI_SCAN_DIR without SQL drivers, checked in /proc/<pid>/environ):
  GET /up / /articles /articles/reset-proof /search?q=a /pricing /stories/<uuid> /login /register /forgot-password  -> 200
  POST /forgot-password -> 302 (token stored in Redis: yes); POST /login (admin) -> 302
  GET /admin/{dashboard,processing,sources,sources/health,categories,articles,users,reports,comments}
      /profile /profile/reading-pass /bookmarks                                                     -> 200
database/database.sqlite present after run: no                                                      RESULT fail=0
```

The first article URL tried was a StoryLens fixture article, which 404s by design (its category/source are not active
configuration); the proof creates one article in a seeded active category/source instead. A first proof run left an
`artisan serve` child alive (`kill` hit the parent only); it was killed and the proof rerun with a busy-port check.

### Setup / runbook commands (Gate 10 input)

```bash
php artisan migrate --force
php artisan storylens schema
php artisan interactions schema
php artisan reading-pass schema
php artisan moderation:audit schema
php artisan sources:history-manage bootstrap
php artisan db:seed --force
php artisan storylens ingest            # optional demo fixture
```

Legacy SQL data: check out tag `pre-sql-removal`, point its `DB_CONNECTION` at the legacy database, run the per-slice
import commands as documented at that tag, then upgrade to this release and run the commands above (except seeding).

### Known limitations

- **Active `.env` (owner):** it still contains `DB_CONNECTION=sqlite`. Nothing breaks (the value is no longer read), but the
  owner should delete that line (and any other `DB_*` keys) from `.env`. No other `.env` change is required: it already
  sets `SESSION_DRIVER/CACHE_STORE/QUEUE_CONNECTION=redis`.
- **First `migrate` on the dev machine** reruns the 8 Mongo index migrations once (the Mongo `migrations` collection is
  empty) and creates the new queue indexes; all are idempotent. Not executed here (no migrate against dev).
- Pending password-reset links issued before deploy (SQL table) are lost; users request a new link. Pending SQL failed
  jobs are not moved (drain/inspect them on the old release before upgrading).
- `cache:clear` on the default store also clears pending reset links (same Redis connection `cache`).
- `CLAUDE.md` (owner WIP) still says tests use SQLite and session/cache/queue use the database driver.
- `database/database.sqlite` remains on disk (gitignored dev data); the owner may archive/delete it.
- laravel-mongodb quirk observed: `db:wipe`/`migrate:fresh` disconnects the Mongo connection object, after which
  `enableQueryLog()` in the same process fails; the test bootstrap therefore uses `migrate` (no wipe).

STATUS (round 0): READY_FOR_REVIEW — superseded by the fix round below.

## Fix round (REVIEW-1)

| Finding | Fix |
|---|---|
| MAJOR-1 code | New `app/Console/DestructiveCommandGuard.php`, applied in `AppServiceProvider::boot()`. Outside `testing` it calls `DB::prohibitDestructiveCommands()`. In Laravel 13.11.2 that sets the `Prohibitable` flag on `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback` and `db:wipe`, so all four requested commands are covered and no extra hook is needed. A `CommandStarting` listener adds the message `Refused: <cmd> would drop MongoDB data or indexes. Nothing was changed.` plus a pointer to the safe reset. Verified on the real CLI with `APP_ENV=local` on the isolated stores: all 5 commands exit 1 with that message. |
| MAJOR-1 tests | `NoSqlRuntimeTest::test_destructive_database_commands_are_refused_outside_testing` runs each command as a real CLI subprocess (`APP_ENV=local`, stores still `vietfeed_test`/DB15) and in-process with the env switched to `local`. Every run is refused. The collection list, the `migrations` count, a document and the `slug_1` index are all unchanged. The suite itself still runs (`testing` is not prohibited). |
| MAJOR-1 safe reset | I chose the documented path ("switch to fresh names, never drop") over a reset command. It needs no new code that can delete data, and with the guard, nothing in the app drops a database any more. The docs give the steps: `MONGO_DB`/`CASSANDRA_KEYSPACE`/empty `REDIS_NOSQL_DB`+`REDIS_CACHE_DB` → `config:clear` → `migrate --force` → 5 schema commands → `db:seed --force` (→ `feeds:fetch`). |
| MAJOR-1 docs | `docs/demo/CHEAT-SHEET-THUYET-TRINH.md` §3 (steps + command table) and item 13, and `docs/demo/SCRIPT-THUYET-TRINH.md` part 4, are rewritten to the rename-not-drop runbook. Same Vietnamese style and structure; only the reset steps and their explanation changed. In `nosql-local.md`, the historical `migrate:fresh` note is fixed and a prominent ⛔ warning opens the slice 5 section. |
| MINOR-1 | Docs say to run `php artisan migrate --step` on the first dev run and never to roll back the index migrations. The `down()` of 7 index migrations now goes through a tolerant `dropIndex()` that ignores NamespaceNotFound (26) and IndexNotFound (27). `up()` is untouched. The 2 migrations already using `dropIndexIfExists` are unchanged. Test `test_index_migration_rollback_tolerates_missing_indexes_and_up_restores_them` runs `down()`, `down()`, `up()` on all 9. |
| MINOR-2 | The slice 5 table documents token loss on `cache:clear`, a flush or `allkeys-lru` eviction. It fails closed: the link is reported invalid and the user requests a new one. |
| MINOR-3 | `TestCase::tearDown` flushes the DB15 `cache` connection through the existing `GuardsRedisTestDatabase::flushTestDatabase` (DB15 assert). The trait is now used in `TestCase`. In tests the cache, default and nosql connections are all DB15, so this empties the test DB; the ReadingPass generation is set afterwards as before. |
| NIT-1 | `ConfigMongoTest`: the homepage reads are now asserted with `assertLessThanOrEqual(17, …)`; the equality check stays for every other URL. |
| NIT-2 | Comment-only change in `docker-compose.yml` (`docker compose config -q` still validates). Fixed `docs/demo/api-token-demo.md` and `docs/demo/storylens-progressive-rendering.md`. |
| NIT-3 | The runbook now starts with `composer install`, `cp .env.example .env`, `key:generate`, `docker compose up -d` + health check, and `npm install --ignore-scripts && npm run build`. |

Files changed in this round: `app/Console/DestructiveCommandGuard.php` (new), `app/Providers/AppServiceProvider.php`, 7 Mongo index
migrations (`down()` only), `tests/TestCase.php`, `tests/Feature/NoSqlRuntimeTest.php` (+2 tests), `tests/Feature/ConfigMongoTest.php`,
`docker-compose.yml` (comment), `docs/database/nosql-local.md`, `docs/demo/{CHEAT-SHEET-THUYET-TRINH,SCRIPT-THUYET-TRINH,api-token-demo,storylens-progressive-rendering}.md`.

### Verification (fix round)

| Command | Result |
|---|---|
| `APP_ENV=local MONGO_DB=vietfeed_test CASSANDRA_KEYSPACE=vietfeed_test REDIS_*=15 php artisan <db:wipe\|migrate:fresh\|migrate:refresh\|migrate:reset\|migrate:rollback> --force` | exit 1 each, "Refused: … Nothing was changed." + safe-reset pointer |
| `php artisan test tests/Feature/NoSqlRuntimeTest.php tests/Feature/ConfigMongoTest.php` × 3 | exit 0 / 0 / 0 (19 tests, 855 assertions) |
| `composer run test` | exit 0 — **386 tests, 8,899 assertions** (48 s; +13 s from re-creating every index in the rollback test) |
| `vendor/bin/pint --test` on the round's 14 touched PHP files | exit 0 |
| `sha256sum -c ~/.vietfeed-control/wip.sha256` | 6 × OK |
| `git status --short` | only this slice's files plus untouched owner WIP; both new untracked files are in place, as is `app/Console/DestructiveCommandGuard.php` |

## Fix round 2 (re-review)

| Item | Fix |
|---|---|
| 1 | `DestructiveCommandGuard::allowed()` now requires `testing` **and** `database.connections.mongodb.database === 'vietfeed_test'`, the same check style as `StoryPipeline::assertSafeTestStores`. Both the `DB::prohibitDestructiveCommands()` flag and the `CommandStarting` message use it. The existing test (renamed `…_refused_outside_testing_or_off_the_test_database`) additionally runs `testing` with config `mongodb.database = vietfeed_not_test`, in-process only: all 5 commands are refused and `allowed()` is false. The config is then restored and `allowed()` is true again. The subprocess part still targets only isolated stores. |
| 2 | Demo reset docs (`CHEAT-SHEET-THUYET-TRINH.md`, `SCRIPT-THUYET-TRINH.md`, `nosql-local.md`) now also switch `REDIS_DB` (queue/sessions/locks) to an empty DB, or say to stop the workers and let the queue drain first. Each has one line noting that Neo4j is shared and old StoryLens nodes remain (UUID-scoped, harmless). |
| 3 | No SQLite mentions remain in `api-token-demo.md` / `storylens-progressive-rendering.md`; round 1 already fixed them (`grep -i "sqlite\|:memory:"` finds nothing). |
| 4 | Kept the `CommandStarting` message. Laravel's warning ("prohibited from running in this environment") does not point to the safe reset; ours does. |

| Command | Result |
|---|---|
| `APP_ENV=local MONGO_DB=vietfeed_test … php artisan db:wipe --force` | exit 1, "Refused: db:wipe would drop MongoDB data or indexes. Nothing was changed." |
| `php artisan test tests/Feature/NoSqlRuntimeTest.php` × 3 | exit 0 / 0 / 0 (7 tests, 170 assertions) |
| `composer run test` | exit 0, 386 tests / 8,911 assertions |
| `vendor/bin/pint --test app/Console/DestructiveCommandGuard.php tests/Feature/NoSqlRuntimeTest.php` | exit 0 |
| `sha256sum -c ~/.vietfeed-control/wip.sha256` | 6 × OK |
| `git status --short` | slice files + untouched owner WIP; new untracked files in place |

STATUS: READY_FOR_REVIEW
- Control (2026-11-18): final gate hit a flaky assertion (reset-token TTL 3601 > 3600, Redis rounds the remaining TTL up); control widened it to ≤ 3601 in tests/Feature/NoSqlRuntimeTest.php (test-only).

## Control acceptance (2026-11-18, Claude Opus 5.5 control) — merge awaits the owner

Workflow: implementation Claude Opus (final, hardest slice); independent reviews Claude Opus — REVIEW-1.md PASS_WITH_NOTES conditional
on MAJOR-1 (migrate:fresh/db:wipe would drop the whole dev Mongo database; demo docs told presenters to run it) → fixed with a code guard
+ safe documented reset; REVIEW-2.md PASS_WITH_NOTES, its two follow-ups applied in fix round 2. Browser regression smoke by GPT 6.1 Terra.

| Check | Result |
|---|---|
| `composer run test` (control, final tree) | exit 0 — 386 tests / 8911 assertions, no SQLite (main fbddbf7: 395 / 7960; 16 importer/SQL-only tests deleted, new NoSqlRuntimeTest) |
| `pint --test` touched PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0, no pdo requirement |
| No-SQL guard | fails the suite on any non-Mongo query/connection (proven by the worker with a temporary SQLite query) |
| Destructive command guard | allowed only in `testing` on `vietfeed_test`; refused elsewhere (tested in-process and via CLI on isolated stores) |
| Owner WIP sha256 | unchanged |

Browser regression (Terra, isolated stores, from-scratch runbook): public pages, register/logout/login (Redis sessions), profile,
reading pass, forgot/reset password via Redis tokens, bookmark/boost/comment/reply/report, all admin pages (reports, sanctions,
sources, Source Health, categories, users, processing), `queue:failed` and `migrate:status` — 6/6 PASS, no 500. Pre-existing finding for
Gate 10: `storylens ingest` fixture articles have no source_id/category_id, so their /articles/{slug} 404s (also before Gate 9.5).

Owner actions after merge (dev PC): optional — remove `DB_CONNECTION=sqlite` and other `DB_*` keys from `.env` (now ignored); run the
first dev migration with `php artisan migrate --step` (reruns the 8 idempotent Mongo index migrations once into the Mongo `migrations`
collection); `database/database.sqlite` is no longer read (keep or delete); update owner-WIP `CLAUDE.md` (still mentions SQLite tests
and database drivers). Presentation docs still describe the homepage "hot" score as SQL (`CHEAT-SHEET-THUYET-TRINH.md:281`) — refresh in
the Gate 10 runbook pass. Legacy SQL data: check out tag `pre-sql-removal`, run the importers there, then upgrade.
