# Gate 9.5 slice 1 — Category and Source on MongoDB

## Implementation

### Plan

Baseline: branch `cutover/config-mongo`, base main `fe067d9`. Before implementation,
`composer run test > /tmp/g95s1-baseline.log 2>&1; echo exit=$?` → **0**,
323 tests / 5157 assertions, 25.458 s. Owner WIP hashes recorded before edits in
`/tmp/g95s1-wip.sha256` (50 paths). Single writer; no staging or commits.

- IDs: Mongo Category/Source `_id` UUIDv7 via `Str::uuid7`, explicit relation keys,
  SQL-equivalent defaults, retained casts/scopes/health. Add unique slug/feed_url
  indexes; sources.url was not unique and remains nonunique (publishers have multiple feeds).
- References: retain UUID strings in articles, embedded interests, filters and queued jobs;
  add a forward-only migration removing the SQL fetch-log FK and widening source_id.
  Preserve explicit SQL connection on fetch-log relations and deletion cleanup.
- Reads: resolve active configuration ids in batches, eager load unconstrained Mongo
  relations, attach interaction counts per SQL store; category/source counts aggregate
  natively and merge before sorting/pagination. Health logs fetched per store in batches.
- Cache: category navigation list uses the existing default cache store (Redis in normal
  runtime), versioned key with TTL, lazy rebuild after eviction, invalidation on model
  saved/deleted events. Layout composer supplies the list; cold/empty lists work.
- Data move: `config:import-sql`, maintenance-window rerunnable copy by slug/feed_url,
  UUIDv7 assigned only on insert; preserve live Mongo configuration on rerun. Remap integer
  and decimal-string article ids, embedded interests, fetch-log strings, and matching
  snapshot UUIDs by natural key. Keep SQL tables. No distributed transaction; rerun converges.
- Story catalog: retain snapshot-only article eligibility and URLs. Resolve snapshot
  identity from canonical config when present, otherwise reuse the snapshot identity
  for that natural key or allocate UUIDv7. Do not auto-publish snapshot-only articles.
- Tests: baseline first, focused regression after each step, new cutover coverage for
  CRUD/validation/indexes/string references/cache eviction/import idempotency and SQL
  query proof; all changed test files three consecutive passes, final full suite,
  platform requirements, touched-file Pint and isolated seeders. Record true exit codes.

Planned files (expand here before any additional edit):

- `app/Models/{Category,Source,Article,SourceFetchLog,User}.php`
- `app/Content/{CategoryList,ArticleCounts,ConfigSqlImport}.php` (new), `app/Content/ContentCatalog.php`
- `app/Console/Commands/ImportSqlConfig.php` (new), `app/Jobs/FetchSourceFeedJob.php`
- `app/Http/Controllers/{Home,Category,Search,Article,Onboarding,Profile}Controller.php`
- `app/Http/Controllers/Admin/{Category,Source,Article}Controller.php`
- `app/Http/Requests/Admin/{StoreCategory,UpdateCategory,StoreSource,UpdateSource}Request.php`
- `app/Providers/AppServiceProvider.php`, `resources/views/layouts/app.blade.php`
- `database/migrations/2026_11_18_100001_create_mongodb_config_indexes.php` (new)
- `database/migrations/2026_11_18_100002_widen_source_fetch_logs_source_id.php` (new)
- `database/seeders/DemoEngagementSeeder.php` (retired interest pivot)
- `tests/TestCase.php`, `tests/Feature/CatalogArticlePagesTest.php`,
  `tests/Feature/ConfigMongoTest.php` (new); other test fixture/storage edits only if needed,
  recorded before editing. `docs/database/nosql-local.md`, this report.

### Decisions and evidence

Plan extension before edit: `tests/Feature/AdminArticleDefectsTest.php` needs its
integer storage assertions/test names changed to UUID string storage; preserve all
invalid-filter behavior assertions. Admin source filters will validate UUIDs.
`CatalogArticlePagesTest` replaces its literal `source=1` probe with a valid absent
UUID fixture and warms the navigation cache before measuring per-store query counts.

### Initial decisions (superseded by REVIEW-1 fixes below)

1. Category/Source now extend the official Mongo model, use `_id`, and allocate
   UUIDv7 through `Str::uuid7` before insertion, matching Article/Story. Explicit
   foreign/local keys prevent Mongo `_id` from becoming an inferred foreign key.
   Defaults preserve SQL's active/prestige/failure defaults; health logic is retained.
2. Unique indexes are `categories.slug` and `sources.feed_url`; homepage `url` was
   never unique and must support the unchanged SourceSeeder's multiple publisher feeds.
   Duplicate feed validation targets Mongo; update rules ignore the current `_id`.
3. Readers query active UUID sets then unconstrained Mongo relations. Interaction
   counts remain native SQL batches (500 parameters/chunk); home reuses existing
   aggregate counts for every card. Category lists attach article/source counts before
   sorting and pagination. Health fetch logs are batched separately on SQL; their FK
   is removed and column widened by a new migration. Source deletion explicitly
   deletes its SQL logs, preserving the old FK cleanup behavior.
4. Retired `Category::users()` / `User::favoriteCategories()` pivot methods are
   removed. Their last writer, DemoEngagementSeeder, merges embedded UUID favorites.
   Onboarding/profile presence validation uses Mongo and a callback retaining boolean
   `is_active=true` (a plain rule parameter becomes string `"1"`, which Mongo rejects).
5. Navigation cache uses the existing default cache store. It stores **plain arrays**
   because this repository disables PHP-class cache deserialization; Mongo models in
   Redis would hydrate as incomplete PHP classes. Key `catalog:categories:active:v1`,
   TTL 300 seconds, saved/deleted invalidation, cold/empty/evicted read reconstruction.
   Layout receives hydrated categories from its composer. No new cache connection.
6. `config:import-sql` delegates to ConfigSqlImport, the only SQL config reader.
   It runs offline after the widening migration, matches natural keys, preserves
   existing Mongo records, imports fillable configuration/health fields and original
   timestamps, remaps both int and decimal-string article references, collapses
   duplicate favorites, remaps SQL fetch logs and matching snapshot identities.
   Each rerun finishes any interrupted remapping without allocating replacement IDs.
7. Story replay remains snapshot-only until explicitly assigned through the existing
   admin flow. New snapshot IDs resolve canonical UUIDs when available, otherwise
   reuse a UUIDv7 snapshot for the same natural key or allocate UUIDv7. The import
   rewrites legacy snapshot identities by slug/feed_url. Public eligibility, names,
   URLs, slugs, ranking formulas and RSS health behavior remain unchanged.

### Initial test edits and reasons

| File | Edits and reason |
|---|---|
| `tests/TestCase.php` | Add categories/sources to guarded Mongo teardown; RefreshDatabase cannot roll them back on SQL. Existing Mongo/SQLite assertions and Redis guards retained. |
| `tests/Feature/AdminArticleDefectsTest.php` | Rename two integer-id test names; change two integer storage assertions to UUIDv7 assertions and update relation-store comment. Preserve every malformed-filter/redirect assertion. |
| `tests/Feature/CatalogArticlePagesTest.php` | Rename integer-relation test; absent source fixture becomes a valid UUID; warm navigation cache before query measurements; expected per-store counts become SQL 2 / Mongo 7 (SQL 3 for count sorts), reflecting moved configuration reads. Existing count bounds, hydration counts, ordering/pagination and public-exclusion assertions retained. |
| `tests/Feature/ConfigMongoTest.php` (new, 9 tests) | Admin CRUD/validation/counts/deletion, explicit string relations and removed FK; guarded real Redis cache writes/eviction; active-only interests; rerunnable import for both old types, snapshots, timestamps, health, seeded natural-key reuse and new disabled categories; index uniqueness/idempotency; RSS command/job; SQL-query and source-code proof plus bounded reads; catalog snapshot/public-eligibility parity. |

The three consecutive final **full-suite** passes include every new/changed test
file and the changed base TestCase. No behavior assertion was removed or weakened.

### Initial verification

All test runs were sequential. Mongo `vietfeed_test`, guarded Redis DB15, SQLite
`:memory:`; seeder-only exception uses the requested throwaway SQLite file. No dev
migration, import, seeder, flush, container restart, staging or commit was performed.
Commands redirected directly to logs and their actual `$?` was captured; no pipes
were used to determine exit status.

| Command (log under `/tmp/`) | Real exit | Evidence |
|---|---:|---|
| `composer run test > /tmp/g95s1-baseline.log 2>&1; echo exit=$?` | 0 | 323 tests / 5157 assertions, 25.458 s |
| `vendor/bin/phpunit tests/Feature/SourceHealthMonitorTest.php tests/Feature/OnboardingInterestsTest.php` (`g95s1-step1.log`) | 2 | New FK migration needed SQLite column-list drop; corrected only the new migration |
| `composer run test` (`g95s1-step2-full.log`) | 2 | Same FK migration defect, run began before focused output was inspected; corrected |
| `vendor/bin/phpunit tests/Feature/SourceHealthMonitorTest.php tests/Feature/OnboardingInterestsTest.php tests/Feature/CatalogArticlePagesTest.php` (`g95s1-step3.log`) | 1 | 18/20 passed; boolean presence and per-store query measurement corrected |
| `vendor/bin/phpunit tests/Feature/ConfigMongoTest.php tests/Feature/OnboardingInterestsTest.php tests/Feature/CatalogArticlePagesTest.php` (`g95s1-step4.log`) | 2 | 20/25 passed; cache serialization, retired relation probe, duplicate RSS warning expectation, home card batching and cache warm fixture corrected |
| Same focused command (`g95s1-step5.log`) | 0 | 25 tests / 748 assertions |
| `composer run test` (`g95s1-step6-full.log`) | 1 | 325/331 passed; integer storage expectation migrated and UUID validation restored malformed-filter behavior |
| `vendor/bin/phpunit tests/Feature/ConfigMongoTest.php tests/Feature/AdminArticleDefectsTest.php tests/Feature/CatalogArticlePagesTest.php` (`g95s1-step7.log`) | 0 | 42 tests / 908 assertions |
| `vendor/bin/phpunit tests/Feature/ConfigMongoTest.php` (`g95s1-refine.log`) | 0 | Final 9 tests / 448 assertions |
| `composer run test > /tmp/g95s1-final-full-1.log 2>&1; echo exit=$?` | 0 | 332 tests / 5605 assertions, 25.872 s; green pass 1 |
| `composer run test > /tmp/g95s1-final-full-2.log 2>&1; echo exit=$?` | 0 | 332 tests / 5605 assertions, 25.921 s; green pass 2 |
| `composer run test -- --display-all-issues > /tmp/g95s1-final-full-3.log 2>&1; echo exit=$?` | 0 | 332 tests / 5605 assertions, 25.305 s; green pass 3; no notices, warnings or deprecations reported |
| `composer check-platform-reqs > /tmp/g95s1-platform.log 2>&1; echo exit=$?` | 0 | PHP 8.4.27 and all package/extension requirements pass |
| `vendor/bin/pint` with explicit paths from `/tmp/g95s1-touched-php.txt` | 0 | Formatting only touched files; `g95s1-pint-format.log`, `g95s1-pint-refine.log` |
| `vendor/bin/pint --test` with those same explicit paths (`g95s1-pint-test.log`) | 0 | Passed; subprocess returncode captured directly; no broad path |
| Python regex scan over `app/**/*.php` (`g95s1-sql-proof.log`) | 0 | Only two SQL categories/sources hits, both in ConfigSqlImport; no unqualified SQL config validation rules |
| `sha256sum --check /tmp/g95s1-wip.sha256 > /tmp/g95s1-wip-verify.log 2>&1; echo exit=$?` | 0 | All 50 protected paths unchanged |
| `git diff --check > /tmp/g95s1-diff-check.log 2>&1; echo exit=$?` | 0 | Clean |
| `git status --short` | 0 | Full final output below; owner WIP remains present |

**Grep proof**, also asserted by ConfigMongoTest alongside executed SQL query logs
for home/category/search/article/load-more/live-search/admin lists/health/dashboard:

```text
app/Content/ConfigSqlImport.php:27: foreach (DB::table('categories')->orderBy('id')->get() as $row) {
app/Content/ConfigSqlImport.php:35: foreach (DB::table('sources')->orderBy('id')->get() as $row) {
```

No runtime `whereHas`, `withCount` or `loadCount` calls remain in `app/` (only
historical explanatory comments). Configuration relations are eager loaded without
cross-store constraints. The 5/25 article admin fixture retains fixed per-store
query counts; the 2/8 public/admin fixture retains fixed SQL query counts.

### Isolated seeder evidence

For **each** of CategorySeeder and SourceSeeder, run twice, directly capturing exit
codes (all four commands exit **0**):

```bash
APP_ENV=testing MONGO_DB=vietfeed_test DB_CONNECTION=sqlite DB_DATABASE=/tmp/g95s1-seeders.sqlite DB_URL='' CACHE_STORE=array php artisan db:seed --class=CategorySeeder --force
APP_ENV=testing MONGO_DB=vietfeed_test DB_CONNECTION=sqlite DB_DATABASE=/tmp/g95s1-seeders.sqlite DB_URL='' CACHE_STORE=array php artisan db:seed --class=SourceSeeder --force
```

Logs: `g95s1-category-seed.log`, `g95s1-source-seed.log`,
`g95s1-category-seed-repeat.log`, `g95s1-source-seed-repeat.log`.
The isolated bootstrap counter `/tmp/g95s1-seed-counts.php`, under the same env,
asserts the Mongo/default/SQLite configuration before querying (both exits **0**).
Both runs: **8 categories / 26 sources / 0 invalid UUID or category references**.
Sorted identity hash was identical:
`10babe5ea27645fabc7a8fbbe8ccaaa9539a63a7daed9bb78319619106a8f79a`.
The throwaway file was deleted; guarded fixture cleanup deleted only those Mongo
test collections (exit **0**, `g95s1-seed-cleanup.log`) before final suites.
SourceSeeder's owner bytes were unchanged; no SQL tables or Redis connections
were needed by the seeder check (`CACHE_STORE=array`).

### Cutover command order (updated by REVIEW-1; operator instructions only)

Choose **ONE path**: fresh configuration **OR** import legacy SQL. **Never seed
then import**. The owner seed changed two Tuổi Trẻ feed URLs; mixing setup paths
can create duplicate sources under old/new natural keys. SourceSeeder is unchanged.

For legacy import: make a read-only SQL backup/export, enter maintenance, drain
in-flight integer-ID RSS jobs and quiesce HTTP/RSS/queue writers. Apply migrations,
then run import with the intended connections selected. Keep natural keys fixed.

```bash
php artisan down
php artisan migrate --force
php artisan config:import-sql
# If duplicate SQL slugs/feed URLs are reported, resolve the listed IDs before retry.
# Verify printed counts and representative article/user/fetch-log UUID references.
# Repeat import safely after interruption; do not run configuration seeders.
php artisan up
```

For fresh setup: migrate, seed CategorySeeder then SourceSeeder; **do not run
config:import-sql**. Empty-cache reads rebuild automatically. Import and model saves
invalidate navigation. Snapshots retain deterministic content UUIDv5s independent
of canonical Mongo `_id`; no snapshot remap or graph rebuild is part of this cutover.
SQL categories/sources remain present and unused until the final SQL removal slice.
These commands were not run against dev data.

### Known limitations / control questions

- No blocking questions. Control owns the chosen maintenance cutover path.
- Import is an offline workflow, not dual writing or a distributed transaction;
  preserve backups and natural keys, and rerun after interruptions. Existing Mongo
  records win, supporting interrupted imports, not mixing seed and import datasets.
- Snapshot-only Articles keep null canonical references until explicitly assigned,
  preserving public eligibility. Snapshot UUIDv5s remain graph content identities;
  canonical Mongo configuration IDs remain UUIDv7.
- Fetch logs/interactions remain SQL. Health computes five recent logs per source
  in SQL before hydration, plus the independent existing global recent-20 list.
- Other review NITs are deferred: cache fill races/missed invalidations may leave
  stale navigation for at most 300 seconds; bulk writes must invalidate explicitly.
  Failed cache invalidation may also leave an old cached entry until TTL expires.
  Legacy in-flight integer-ID jobs must be drained before cutover; the new job uses
  UUID strings and does not translate old queued payloads. Redundant active-ID
  plucks remain bounded queries and are a later optimization.
- Named FK removal uses the real introspected name. SQLite returns unnamed keys
  and requires a column-list fallback. Named MySQL/PostgreSQL paths are not live
  integration-tested here because this task permits SQLite `:memory:` only.

### Files changed

- `app/Console/Commands/ImportSqlConfig.php`
- `app/Content/ArticleCounts.php`
- `app/Content/CategoryList.php`
- `app/Content/ConfigSqlImport.php`
- `app/Http/Controllers/Admin/ArticleController.php`
- `app/Http/Controllers/Admin/CategoryController.php`
- `app/Http/Controllers/Admin/SourceController.php`
- `app/Http/Controllers/ArticleController.php`
- `app/Http/Controllers/CategoryController.php`
- `app/Http/Controllers/HomeController.php`
- `app/Http/Controllers/OnboardingController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/SearchController.php`
- `app/Http/Requests/Admin/StoreCategoryRequest.php`
- `app/Http/Requests/Admin/StoreSourceRequest.php`
- `app/Http/Requests/Admin/UpdateCategoryRequest.php`
- `app/Http/Requests/Admin/UpdateSourceRequest.php`
- `app/Jobs/FetchSourceFeedJob.php`
- `app/Models/Article.php`
- `app/Models/Category.php`
- `app/Models/Source.php`
- `app/Models/Concerns/NormalizesConfigTypes.php`
- `app/Models/SourceFetchLog.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `database/migrations/2026_11_18_100001_create_mongodb_config_indexes.php`
- `database/migrations/2026_11_18_100002_widen_source_fetch_logs_source_id.php`
- `database/seeders/DemoEngagementSeeder.php`
- `resources/views/layouts/app.blade.php`
- `tests/Feature/AdminArticleDefectsTest.php`
- `tests/Feature/CatalogArticlePagesTest.php`
- `tests/Feature/ConfigMongoTest.php`
- `tests/TestCase.php`
- `docs/database/nosql-local.md`
- `.agent/tasks/sql-cutover-config/GATE-REPORT-9.5-1.md`

### Initial worktree (before review)

```text
 M .agent/tasks/infra-cassandra-spike/STATUS.md
 M .agent/tasks/mongo-foundation/STATUS.md
 M .agent/tasks/neo4j-foundation/STATUS.md
 M CLAUDE.md
 M app/Content/ContentCatalog.php
 M app/Http/Controllers/Admin/ArticleController.php
 M app/Http/Controllers/Admin/CategoryController.php
 M app/Http/Controllers/Admin/SourceController.php
 M app/Http/Controllers/ArticleController.php
 M app/Http/Controllers/CategoryController.php
 M app/Http/Controllers/HomeController.php
 M app/Http/Controllers/OnboardingController.php
 M app/Http/Controllers/ProfileController.php
 M app/Http/Controllers/SearchController.php
 M app/Http/Requests/Admin/StoreCategoryRequest.php
 M app/Http/Requests/Admin/StoreSourceRequest.php
 M app/Http/Requests/Admin/UpdateCategoryRequest.php
 M app/Http/Requests/Admin/UpdateSourceRequest.php
 M app/Jobs/FetchSourceFeedJob.php
 M app/Models/Article.php
 M app/Models/Category.php
 M app/Models/Source.php
 M app/Models/SourceFetchLog.php
 M app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M database/seeders/DemoEngagementSeeder.php
 M database/seeders/SourceSeeder.php
 M docs/database/nosql-local.md
 M resources/views/layouts/app.blade.php
 M tests/Feature/AdminArticleDefectsTest.php
 M tests/Feature/CatalogArticlePagesTest.php
 M tests/TestCase.php
?? .agent/handoff/
?? .agent/migration-pc/
?? .agent/tasks/claude-code-handoff/
?? .agent/tasks/infra-cassandra-spike/REVIEW.md
?? .agent/tasks/infra-cassandra-spike/T1-compose-health.md
?? .agent/tasks/infra-cassandra-spike/T2-cassandra-spike.md
?? .agent/tasks/infra-cassandra-spike/TASK.md
?? .agent/tasks/mongo-foundation/REVIEW.md
?? .agent/tasks/mongo-foundation/T1-mongo-package.md
?? .agent/tasks/mongo-foundation/T2-mongo-auth.md
?? .agent/tasks/mongo-foundation/T3-mongo-crud.md
?? .agent/tasks/mongo-foundation/TASK.md
?? .agent/tasks/neo4j-foundation/REVIEW.md
?? .agent/tasks/neo4j-foundation/T1-neo4j-client.md
?? .agent/tasks/neo4j-foundation/TASK.md
?? .agent/tasks/redis-cutover/CONTROL-2026-11-02.md
?? .agent/tasks/redis-cutover/CONTROL-2026-11-14.md
?? .agent/tasks/redis-cutover/IMPLEMENT-2026-11-02.md
?? .agent/tasks/redis-cutover/IMPLEMENT-2026-11-14.md
?? .agent/tasks/redis-cutover/IMPLEMENT-PROMPT-2026-11-02.md
?? .agent/tasks/redis-cutover/PR-DRAFT-14.md
?? .agent/tasks/redis-cutover/REVIEW-2026-11-02.md
?? .agent/tasks/redis-cutover/REVIEW-2026-11-14.md
?? .agent/tasks/redis-cutover/REVIEW-PROMPT-2026-11-02.md
?? .agent/tasks/redis-cutover/REVIEW.md
?? .agent/tasks/redis-cutover/T1-redis-sessions.md
?? .agent/tasks/redis-cutover/T2-redis-cache-queue.md
?? .agent/tasks/redis-cutover/T3-redis-streams.md
?? .agent/tasks/redis-cutover/TASK.md
?? .agent/tasks/sql-cutover-config/
?? .pi/
?? app/Console/Commands/ImportSqlConfig.php
?? app/Content/ArticleCounts.php
?? app/Content/CategoryList.php
?? app/Content/ConfigSqlImport.php
?? database/migrations/2026_11_18_100001_create_mongodb_config_indexes.php
?? database/migrations/2026_11_18_100002_widen_source_fetch_logs_source_id.php
?? docs/agents/
?? pi-warden.md
?? tests/Feature/ConfigMongoTest.php
```

**Initial status superseded: REVIEW-1 returned FAIL; see fix round below.**

## Fix round (REVIEW-1)

### Plan

Binding review fixes: add `app/Models/Concerns/NormalizesConfigTypes.php` and apply
it to Category/Source; normalize Category request checkboxes. Restore ContentCatalog
snapshot UUIDv5 identities verbatim from main and remove import snapshot writes.
Add duplicate-natural-key preflight in ConfigSqlImport and clean command failure
output containing IDs only. Limit health logs with SQL ROW_NUMBER per source.
Drop introspected FK names (SQLite unnamed constraints require column fallback).
CategoryList catches cache failures and queries Mongo directly. Extend
ConfigMongoTest for real parsed form payloads/BSON types, SQL flags, stable graph
identity/counts, bounded log hydration, duplicate abort and failed-cache rendering.
Update database docs/cutover notes; preserve other review nits as limitations.
Touched files are the above new trait, Category/Source models, Store/UpdateCategory
requests, ContentCatalog, ConfigSqlImport, ImportSqlConfig command, admin
SourceController, CategoryList, the slice's new FK migration, ConfigMongoTest,
`docs/database/nosql-local.md` and this report. Owner checksums verified before edits:
`sha256sum --check /tmp/g95s1-wip.sha256` exit 0 (round1-wip-before log).

### Review item mapping and regression evidence

The earlier verification records are historical. REVIEW-1 correctly rejected the
initial type handling and snapshot decision; this section and the updated cutover
note above describe the final implementation. All binding items are addressed.

| Item | Change | Test / evidence |
|---|---|---|
| **B1** admin flags | Shared model `setAttribute` normalization stores Category/Source `is_active` as bool. Category Store/Update requests use `prepareForValidation()` with `boolean('is_active')`. | `test_real_admin_checkbox_payloads_store_bools_on_create_and_update` parses the exact browser `is_active=0&is_active=1` checked payload and hidden-only `is_active=0` unchecked payload, creates both models in each state, then updates each to the opposite state; checks raw BSON booleans and active-scope membership throughout. |
| **B2** SQL flags | Import uses the same model write normalization; SQL integers cannot reach Mongo as active-flag integers. | `test_import_sql_integer_flags_become_queryable_bson_bools` imports SQL category/source rows with explicit `1` and `0`, checks both active counts equal 1 and every stored flag has boolean type. |
| **MINOR prestige / numeric health** | Source prestige, consecutive_failures, last_items_found, last_valid_items, last_duplicate_count, last_duration_ms normalize on model assignment to int or null. | Real form submits prestige string `"5"` and checks raw BSON int; `test_model_writes_normalize_flags_and_nullable_numeric_health_fields` checks all six numeric fields for integer and null writes. |
| **M1** snapshot identity | ContentCatalog restored **byte-for-byte to main fe067d9**, including the exact URL-namespace UUIDv5 schemes `vietfeed:source:<feed_url>` / `vietfeed:category:<slug>`. Import no longer reads/writes snapshot UUIDs or prints a snapshots-remapped count. | `test_catalog_snapshot_identity_and_source_count_are_independent_of_configuration` ingests a feed before config exists, creates canonical config, ingests a second item from that feed into the same Story, verifies identical expected UUIDv5s and unchanged source_count=1 (article_count=2). Rerunnable-import test checks snapshot UUIDv5s remain untouched. A read-only `git show fe067d9:app/Content/ContentCatalog.php` byte comparison passes. |
| **M2** bounded health logs | A SQL ROW_NUMBER window partitions logs by source_id and orders created_at DESC, id DESC; outer SQL predicate recent_rank <= 5 runs before hydration. Mongo sources receive grouped results. No PHP take(5). | `test_health_limits_history_in_sql_before_hydrating_each_source` creates 80 SQL logs across two sources; exactly 10 ranked logs hydrate (ranks 1..5 per source), with one window query and the bound in SQL/bindings. The independent existing global recent-20 query is preserved. |
| **MINOR duplicate legacy keys** | Import reads/preflights all SQL slug/feed_url groups before any copy/remap/cache mutation, then aborts nonzero with table/key and duplicate IDs only. The command catches the preflight LogicException cleanly. | `test_import_duplicate_natural_keys_abort_before_any_write_and_only_list_ids` tests combined duplicates, sources-only duplicates and categories-only duplicates. Exact CLI output includes IDs and excludes the private fixture feed URL; Mongo config, article references, favorites and logs remain unchanged on refusal. SQLite slug uniqueness is relaxed only inside the isolated fixture transaction. |
| **MINOR cutover paths** | Updated this report and local database docs: choose **fresh seed OR legacy import; never seed then import**. Owner seed changed two Tuổi Trẻ feed URLs, so mixing paths creates separate old/new natural-key records. | `test_unchanged_seeders_keep_stable_ids_and_store_queryable_types` runs CategorySeeder/unchanged SourceSeeder twice on test stores: 8 active categories, 26 active sources, stable source IDs, bool flags and integer prestige. Owner file hash unchanged. |
| **NIT real FK name** | Slice migration uses introspected `$key['name']`; only unnamed SQLite constraints fall back to the introspected column list, as Laravel requires. | Every isolated migration run plus CRUD regression verifies no source_fetch_logs FK remains and UUID logs insert/resolve normally. No guessed production constraint name remains. Named-server live execution is deferred; only SQLite is authorized here. |
| **NIT cache down** | CategoryList catches cache reads/fills and queries Mongo directly. Failed cache invalidation cannot fail an already-successful canonical write. | `test_layout_and_category_writes_survive_a_failing_cache_store` uses an actual failing custom cache store (get/forget throw), verifies direct Mongo results and successful layout renders after create/update/delete. Existing guarded DB15 eviction/invalidation test remains green. |
| **Other NITs** | Documented, unchanged: TTL-bounded fill race, drain old integer-ID queued jobs, redundant bounded active-ID plucks. | See Known limitations above; no new product behavior or job translation introduced. |

Only `ConfigMongoTest.php` changed this round. The other previously edited test
files (AdminArticleDefectsTest and CatalogArticlePagesTest) were included in each
three-pass regression invocation. Base TestCase and Redis live DB15 guard remain
unchanged this round. All suites were sequential, Mongo `vietfeed_test` / SQLite
`:memory:` / guarded Redis DB15. The unchanged seeder check is now also automated
inside that isolated feature test; no dev commands were run.

### Fix-round verification

All commands redirected stdout/stderr directly to `/tmp/` logs and captured the
real exit code. Python subprocess checks use the actual process returncode.

| Command | Exit | Result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/ConfigMongoTest.php > /tmp/g95s1-r1-focused-initial.log 2>&1; echo exit=$?` | 1 | 14/15 passed; duplicate-output fixture expected two output calls but the command emits one combined line; corrected to assert its exact ID-only line. No implementation relaxation. |
| Same focused command (`g95s1-r1-focused-corrected.log`) | 0 | 15 tests / 587 assertions, before adding the unchanged-seeder regression. |
| `vendor/bin/phpunit tests/Feature/ConfigMongoTest.php tests/Feature/AdminArticleDefectsTest.php tests/Feature/CatalogArticlePagesTest.php > /tmp/g95s1-r1-tests-1.log 2>&1; echo exit=$?` | 0 | 50 tests / 1122 assertions, 2.695 s, consecutive pass 1. |
| Same three-file command (`g95s1-r1-tests-2.log`) | 0 | 50 tests / 1122 assertions, 2.641 s, consecutive pass 2. |
| Same three-file command (`g95s1-r1-tests-3.log`) | 0 | 50 tests / 1122 assertions, 2.896 s, consecutive pass 3. |
| `vendor/bin/pint` with explicit touched paths from `/tmp/g95s1-r1-touched-php.txt` (`g95s1-r1-pint-format.log`) | 0 | Touched files only. |
| `vendor/bin/pint --test` with those same explicit paths (`g95s1-r1-pint-test.log`) | 0 | Passed. |
| SQL runtime scan / ContentCatalog byte comparison (`g95s1-r1-sql-proof.log`) | 0 | Only SQL config readers remain ConfigSqlImport lines 25–26; ContentCatalog exactly equals main. |
| `sha256sum --check /tmp/g95s1-wip.sha256 > /tmp/g95s1-r1-wip-verify.log 2>&1; echo exit=$?` | 0 | All 50 owner WIP hashes unchanged, including SourceSeeder. |
| `git diff --check > /tmp/g95s1-r1-diff-check.log 2>&1; echo exit=$?` | 0 | Clean. |
| `composer run test -- --display-all-issues > /tmp/g95s1-r1-full.log 2>&1; echo exit=$?` | 0 | **339 tests / 5803 assertions**, 22.734 s; no notices, warnings or deprecations reported. |
| `git status --short > /tmp/g95s1-r1-final-status.log; echo exit=$?` | 0 | Final worktree captured below; no staging, commits, stashes or restore operations. |

### Fix-round final worktree

```text
 M .agent/tasks/infra-cassandra-spike/STATUS.md
 M .agent/tasks/mongo-foundation/STATUS.md
 M .agent/tasks/neo4j-foundation/STATUS.md
 M CLAUDE.md
 M app/Http/Controllers/Admin/ArticleController.php
 M app/Http/Controllers/Admin/CategoryController.php
 M app/Http/Controllers/Admin/SourceController.php
 M app/Http/Controllers/ArticleController.php
 M app/Http/Controllers/CategoryController.php
 M app/Http/Controllers/HomeController.php
 M app/Http/Controllers/OnboardingController.php
 M app/Http/Controllers/ProfileController.php
 M app/Http/Controllers/SearchController.php
 M app/Http/Requests/Admin/StoreCategoryRequest.php
 M app/Http/Requests/Admin/StoreSourceRequest.php
 M app/Http/Requests/Admin/UpdateCategoryRequest.php
 M app/Http/Requests/Admin/UpdateSourceRequest.php
 M app/Jobs/FetchSourceFeedJob.php
 M app/Models/Article.php
 M app/Models/Category.php
 M app/Models/Source.php
 M app/Models/SourceFetchLog.php
 M app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M database/seeders/DemoEngagementSeeder.php
 M database/seeders/SourceSeeder.php
 M docs/database/nosql-local.md
 M resources/views/layouts/app.blade.php
 M tests/Feature/AdminArticleDefectsTest.php
 M tests/Feature/CatalogArticlePagesTest.php
 M tests/TestCase.php
?? .agent/handoff/
?? .agent/migration-pc/
?? .agent/tasks/claude-code-handoff/
?? .agent/tasks/infra-cassandra-spike/REVIEW.md
?? .agent/tasks/infra-cassandra-spike/T1-compose-health.md
?? .agent/tasks/infra-cassandra-spike/T2-cassandra-spike.md
?? .agent/tasks/infra-cassandra-spike/TASK.md
?? .agent/tasks/mongo-foundation/REVIEW.md
?? .agent/tasks/mongo-foundation/T1-mongo-package.md
?? .agent/tasks/mongo-foundation/T2-mongo-auth.md
?? .agent/tasks/mongo-foundation/T3-mongo-crud.md
?? .agent/tasks/mongo-foundation/TASK.md
?? .agent/tasks/neo4j-foundation/REVIEW.md
?? .agent/tasks/neo4j-foundation/T1-neo4j-client.md
?? .agent/tasks/neo4j-foundation/TASK.md
?? .agent/tasks/redis-cutover/CONTROL-2026-11-02.md
?? .agent/tasks/redis-cutover/CONTROL-2026-11-14.md
?? .agent/tasks/redis-cutover/IMPLEMENT-2026-11-02.md
?? .agent/tasks/redis-cutover/IMPLEMENT-2026-11-14.md
?? .agent/tasks/redis-cutover/IMPLEMENT-PROMPT-2026-11-02.md
?? .agent/tasks/redis-cutover/PR-DRAFT-14.md
?? .agent/tasks/redis-cutover/REVIEW-2026-11-02.md
?? .agent/tasks/redis-cutover/REVIEW-2026-11-14.md
?? .agent/tasks/redis-cutover/REVIEW-PROMPT-2026-11-02.md
?? .agent/tasks/redis-cutover/REVIEW.md
?? .agent/tasks/redis-cutover/T1-redis-sessions.md
?? .agent/tasks/redis-cutover/T2-redis-cache-queue.md
?? .agent/tasks/redis-cutover/T3-redis-streams.md
?? .agent/tasks/redis-cutover/TASK.md
?? .agent/tasks/sql-cutover-config/
?? .pi/
?? app/Console/Commands/ImportSqlConfig.php
?? app/Content/ArticleCounts.php
?? app/Content/CategoryList.php
?? app/Content/ConfigSqlImport.php
?? app/Models/Concerns/
?? database/migrations/2026_11_18_100001_create_mongodb_config_indexes.php
?? database/migrations/2026_11_18_100002_widen_source_fetch_logs_source_id.php
?? docs/agents/
?? pi-warden.md
?? tests/Feature/ConfigMongoTest.php
```

**STATUS: READY_FOR_REVIEW**

## Control acceptance (2026-11-18, Claude Opus 5.5 control)

Workflow: implementation GPT 6.1 Sol; independent read-only reviews by Claude Opus subagents — REVIEW-1.md FAIL (2 BLOCKER, 2 MAJOR),
fixed; REVIEW-2.md PASS_WITH_NOTES (NITs only). Browser acceptance GPT 6.1 Terra.

| Check | Result |
|---|---|
| `composer run test` (control) | exit 0 — 339 tests / 5803 assertions (main fe067d9: 323 / 5157) |
| `pint --test` touched PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| SQL reads of categories/sources | only `App\Content\ConfigSqlImport` |
| ContentCatalog vs main | identical (uuid5 snapshot ids unchanged) |
| Owner WIP sha256 | unchanged; SourceSeeder.php runs unchanged (isolated seeding: 8 categories / 26 sources) |

Browser (headless Chromium, Terra, isolated Mongo `vietfeed_test` / Redis DB15 / throwaway SQLite): home with 8 category tabs; category
create via real form → in nav, name-only edit stays visible (old string is_active bug), deactivate/reactivate; source create (prestige 4),
edit, toggle; onboarding interests saved and shown on profile; category and search pages 200; category delete refused while it has
sources, allowed after. No 500. Terra marked step 4 FAIL because the Source Health "recent logs" table showed 7 logs of one source:
control verified this table is the global `SourceFetchLog::latest()->take(20)` list, byte-identical to main — the ≤5-per-source bound
applies to the per-source `fetchLogs` relation (tested). The control criterion was wrong, not the app; step 4 counts as PASS (parity).

Cutover (dev, owner decision 2026-11-18): fresh path — seed (`db:seed`) and re-ingest RSS (`feeds:fetch`), no legacy import.
- MySQL 8.4 rehearsal (control, throwaway container): legacy schema with FK on source_fetch_logs → migrate slice: FK removed, source_id varchar(36) with index; rollback + re-migrate exit 0.
