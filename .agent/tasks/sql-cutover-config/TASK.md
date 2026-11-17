# Gate 9.5 slice 1 — Category and Source on MongoDB

Canonical sources: `docs/planning/technical-gates.md` Gate 9.5, `docs/specs/vietfeed-nosql-storylens.md` §9.1 and §13,
`docs/architecture/database-feature-map.md` (Category CRUD: MongoDB + Redis cache; Source CRUD/prestige tier: MongoDB, fetch
history Cassandra later), `docs/planning/project-scope-notes.md` (UUIDv7 canonical ids).

One slice, one branch, one PR: branch `cutover/config-mongo`, base `main` `fe067d9`.

Goal: `Category` and `Source` become MongoDB models; every reader/writer (public pages, admin CRUD, onboarding, RSS ingestion,
source health, seeders, Story catalog) keeps working; no runtime path reads the SQL `categories`/`sources` tables.

## Recon facts (control, 2026-11-18)

- Models: `app/Models/{Category,Source}.php` (SQL). `Category` has `sources()`, `articles()`, legacy `users()` pivot (retired in
  Gate 3: users keep `favorite_category_ids` on the Mongo user doc). `Source` has `category()`, `articles()`, `fetchLogs()`
  (`SourceFetchLog` stays on SQL in this slice; it moves to Cassandra in a later slice — keep it working by string id).
- References: Mongo `articles.category_id/source_id` (SQL ints today), `users.favorite_category_ids` (ints), SQL
  `source_fetch_logs.source_id`, `ContentCatalog` builds `source_snapshot`/`category_snapshot` with deterministic uuid5 values
  (`vietfeed:source:<feed_url>`, `vietfeed:category:<slug>`).
- ~40 files reference them (controllers incl. Admin Category/Source/Article/Dashboard, Home/Category/Search/Article/Onboarding/
  Profile, `FetchFeeds`, `FetchSourceFeedJob`, `FeedIngestionService`, `AppServiceProvider` view composers, Blade views, seeders,
  many tests). Seeders use `Category::updateOrCreate` / `Source::updateOrCreate` / `Category::pluck('id','slug')`, so owner WIP
  `database/seeders/SourceSeeder.php` must keep working **without being edited**.

## Required outcomes

1. `Category` and `Source` on the `mongodb` connection with UUIDv7 string `_id`; unique indexes (`categories.slug`,
   `sources.feed_url`, `sources.url` if currently unique) created by an idempotent migration; keep fillable/casts/scopes/
   `health_status`.
2. All references use the string ids: Mongo articles (`category_id`, `source_id`), users (`favorite_category_ids`), SQL
   `source_fetch_logs.source_id` (widen the column via a new migration if it is an integer FK; no FK to a Mongo collection).
   No cross-store `whereHas`/`withCount`/eager constraints; batch lookups per store, no N+1 (home, category, search, admin lists,
   dashboard counts).
3. Admin Category/Source CRUD, validation rules (`unique:` / `exists:` must target `mongodb.categories` / `mongodb.sources`),
   onboarding, RSS fetch command/job/ingestion and Source Health all work; the existing behaviour and tests stay green (tests may
   change only fixtures/ids/storage assertions; list every edit with its reason).
4. Redis cache for the category list used by layouts/view composers (feature map), on the existing cache store, invalidated on
   Category writes; correct when the cache is empty/evicted.
5. Data move: an idempotent artisan command copies SQL categories/sources into MongoDB (new UUIDv7 ids, keep slug/feed_url as
   natural keys so reruns match), remaps Mongo article references, user favorites and SQL `source_fetch_logs.source_id`, and
   prints counts; safe to rerun; SQL tables left in place, unused (dropped in the final slice). Fresh setups simply run the seeders.
6. Tests in `composer run test` (Mongo `vietfeed_test`, Redis DB15 guard, SQLite `:memory:`) for CRUD, references, cache
   invalidation, the data-move command (idempotency + remapping) and a grep-style proof that no runtime code queries SQL
   `categories`/`sources` (only the data-move command).
7. Docs: `docs/database/nosql-local.md` collections/indexes; a short cutover note (command order) in this folder's
   `GATE-REPORT-9.5-1.md`.

## Test isolation (hard requirements)

Mongo `vietfeed_test`, Redis DB15 behind the live `getDbNum()` guard, SQLite `:memory:`; Cassandra not needed. Never touch dev
data (Mongo `vietfeed`, Redis DB0/1/2); no FLUSHALL, no `migrate:fresh`/seed/new commands against dev, no container restarts.
Run suites sequentially.

## Non-goals

Moving `Comment`, `Bookmark`, `Boost`, `ArticleUnlock`, `SourceFetchLog` (later slices); dropping SQL tables; changing product
behaviour, URLs or slugs; editing owner WIP (`database/seeders/SourceSeeder.php` must work unchanged).

## Stop conditions

Need to edit owner WIP; product change; a failure not fixable in scope; container restart or OOM.
