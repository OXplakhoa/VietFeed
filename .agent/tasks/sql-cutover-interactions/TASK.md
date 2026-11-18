# Gate 9.5 slice 3a — Bookmark and Boost on MongoDB, activity events to Cassandra, real trending

Canonical sources: `docs/planning/technical-gates.md` Gate 9.5, `docs/specs/vietfeed-nosql-storylens.md` §9.1–9.4, §10, §13,
`docs/architecture/database-feature-map.md` (Bookmark: MongoDB current state, Cassandra activity, Redis cache; Boost: MongoDB current
state, Cassandra activity, Redis ranking; "Bookmark/Boost hiện hành vẫn ở MongoDB. Cassandra chỉ nhận BOOKMARKED, UNBOOKMARKED,
BOOSTED, UNBOOSTED events"). Slice 3 is split by control: 3a (this) Bookmark/Boost; 3b later ArticleUnlock/Reading Pass.

One slice, one branch, one PR: branch `cutover/interactions-mongo`, base `main` `1f17c1c`.

## Recon facts (control, 2026-11-18)

- `Bookmark` and `Boost` are SQL models (`user_id` Mongo string, `article_id` Mongo UUID string; unique `(user_id, article_id)` in SQL).
  Used by `BookmarkController`, `BoostController` (+ `/api/v1` boost toggle from Gate 8 reusing `BoostController`), `ArticleController`,
  `HomeController`, `CategoryController`, `SearchController`, `Admin\{Article,Dashboard,User}Controller`, `App\Content\ArticleCounts`,
  `Article`/`User` relations, views (article card, article page, home, admin), `DemoEngagementSeeder`, tests.
- Temp-ban enforcement (9a): web bookmark/boost return 403 for temp-banned users (`canBookmark`/`canBoost`), API boost checks `canBoost`.
- Gate 5 infrastructure exists but is fed only by synthetic data: `App\Interaction\{InteractionEvent,InteractionStream,Trending,
  RedisResult}`, `php artisan interactions consume|inspect|replay|top|rebuild`. Controllers do not publish events yet.
- Cassandra pattern to reuse: `App\StoryProcessing\CassandraClient` (keyspace guard), `App\Moderation\ModerationAudit` (schema command,
  idempotent insert by event id, DLQ/replay via `App\Processing\StreamConsumer`). MongoDB standalone (no transactions); Redis allkeys-lru.

## Required outcomes

1. `Bookmark` and `Boost` on MongoDB (UUIDv7 `_id`, unique `{user_id, article_id}`, indexes for per-user lists and per-article counts) via
   an idempotent migration. Toggle semantics unchanged (web JSON responses, API responses, counts shown on cards/pages), temp-ban rules
   unchanged. Concurrent double toggles must not create duplicates (unique index + duplicate-key handling) and must not drift counts.
2. Every successful state change publishes exactly one interaction event (BOOKMARKED / UNBOOKMARKED / BOOSTED / UNBOOSTED, UUIDv7
   event id, user, article, occurred_at) after the Mongo write, through the existing interaction stream; a Redis failure never fails the
   user action (logged without payload; a reemit/rebuild path exists from Mongo current state where history is missing).
3. Consumers: (a) Cassandra activity history — query-first tables for "a user's recent activity" and "an article's activity over time"
   (choose partitions/clustering, document them), idempotent by event id, DLQ + replay; (b) Redis trending now fed by real BOOSTED /
   UNBOOSTED events (keep the synthetic replay source for the demo, clearly labelled). `interactions rebuild` can rebuild trending from
   Cassandra history or Mongo current state (choose, justify).
4. Counts (article bookmark/boost counts, dashboard totals, admin user list) read MongoDB with one aggregation per page (no N+1, no
   cross-store whereHas/withCount).
5. Data move: idempotent `interactions:import-sql` copying SQL bookmarks/boosts (natural key user+article, `legacy_sql_id`), optionally
   emitting backfill events flagged as backfill; counts printed; SQL tables left unused. Dev choice remains "fresh" (re-seed).
6. Tests on isolated stores (Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`, SQLite `:memory:`): toggles web + API,
   temp-ban 403s, duplicate/concurrent toggle, event published once per change, consumer idempotency (duplicate delivery, replay),
   Redis-down action still succeeds, trending from real boosts, import idempotency, grep proof that only the import reads SQL
   bookmarks/boosts. Existing tests stay green with storage-only edits (each listed with reason).
7. Docs: `docs/database/nosql-local.md` (collections, stream, Cassandra tables, native inspection queries), `GATE-REPORT-9.5-3a.md`.

## Hard rules / isolation

Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`, SQLite `:memory:`. Never touch dev data (Mongo `vietfeed`, Redis
DB0/1/2, Cassandra `vietfeed`); no FLUSHALL, no migrate/seed/new commands against dev, no container starts/restarts. Sequential suites.
Never edit existing migrations. No new dependencies. Owner WIP untouched.

## Non-goals

ArticleUnlock / Reading Pass (3b), SourceFetchLog (slice 4), dropping SQL tables, UI changes beyond keeping current behaviour.

## Stop conditions

Product change needed; owner WIP edit; failure not fixable in scope; container restart or OOM.
