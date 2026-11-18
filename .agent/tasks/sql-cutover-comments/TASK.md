# Gate 9.5 slice 2 — Comments on MongoDB

Canonical sources: `docs/planning/technical-gates.md` Gate 9.5, `docs/specs/vietfeed-nosql-storylens.md` §7 story 13, §9.1, §13,
`docs/architecture/database-feature-map.md` (Comment/reply CRUD: MongoDB; Redis rate limit/cache; Cassandra moderation history),
Gate 9a/9b contracts (`.agent/tasks/report-repair/`, `.agent/tasks/moderation-nosql/`) whose behaviour must not change.

One slice, one branch, one PR: branch `cutover/comments-mongo`, base `main` `8b4c3c3`.

Goal: `Comment` (incl. replies and the moderation hide fields) becomes a MongoDB model; commenting, replying, editing, deleting,
reporting, hiding/unhiding and all moderation pages behave exactly as today; no runtime path reads SQL `comments`.

## Recon facts (control, 2026-11-18)

- `App\Models\Comment` (SQL): `user_id` (Mongo user string), `article_id` (Mongo UUID string), `parent_id` (self FK,
  `cascadeOnDelete` — deleting a comment deletes its replies), `body`, `is_hidden` (not fillable; only the moderation path sets it),
  `hidden_by_sanction_id` (string, widened in 9b); scopes `visible`, `visibleTo`, `hidden`; relations `user`, `article`, `parent`,
  `replies`, `reports`.
- Integer `comment_id` is embedded in moderation: Mongo `reports.comment_id`, `moderation_cases.comment_id` (UNIQUE — the per-comment
  case is the 9b atomicity point), case `events[].comment_id`, `ModerationEvents` casts `(int)`, Cassandra
  `moderation_events_by_case.comment_id bigint` (Cassandra cannot change a column type), `ModerationQueries` (inbox grouping, live
  state lookups `Comment::whereIn('id', …)`), `ModerationService` hide/unhide (SQL conditional updates on `comments`),
  `SqlImport` (relinks `comments.hidden_by_sanction_id`), `ModerationRepair` revoked-hide sweep.
- Other users: `CommentController`, `Admin\CommentController`, `Admin\DashboardController` / `Admin\UserController` counts (manual
  grouped SQL counts from Gate 7 C1), `HomeController`, `ReportController`, `Article`/`User`/`Report` relations, views
  (`articles/show`, comment components, admin articles/users), seeders `DemoEngagementSeeder`, `ModerationDemoSeeder`.
- MongoDB is standalone (no multi-document transactions); Redis is allkeys-lru.

## Required outcomes

1. `Comment` on MongoDB with UUIDv7 `_id`; `parent_id` string; indexes (article_id + created_at, parent_id, user_id,
   hidden_by_sanction_id) by an idempotent migration; `is_hidden` stays non-fillable and is written only by the moderation path.
2. Hide/unhide become single-document conditional updates on the Mongo comment (`is_hidden:false` → hide with
   `hidden_by_sanction_id:S`; unhide only where `hidden_by_sanction_id:S`), keeping every 9a/9b rule (owner decision 1, R4, F1 sweep).
3. Moderation references switch to the comment's string id everywhere in MongoDB (reports, cases incl. the unique index, events) and
   in code (no `(int)` casts). Cassandra: keep the existing `comment_id bigint` column untouched for history; add a `comment_ref text`
   column (idempotent `ALTER TABLE … ADD`, tolerate "already exists"), write new events to `comment_ref`, and make timeline reads show
   either. Never rewrite Cassandra history.
4. Deleting a comment deletes its replies (previous FK cascade) and keeps all reports/snapshots (9a rule 13); admin comment pages,
   article page (visibleTo, author-sees-hidden, single sanction read), counts on admin dashboard/user list (batched per store, no N+1)
   unchanged in behaviour.
5. Data move: idempotent artisan command copying SQL comments (parents before replies, id map with `legacy_sql_id` unique) to MongoDB,
   remapping `reports.comment_id`, `moderation_cases.comment_id` (+ events refs), `hidden_by_sanction_id` carried over; reruns skip;
   counts printed; SQL table left in place, unused (dropped in the final slice). Document the cutover order (maintenance mode →
   migrate → import → `moderation:rebuild-queue` → up) and the dev choice (fresh: re-seed DemoEngagement instead of importing).
6. Tests (Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`, SQLite `:memory:`): all existing Moderation*, ApiTokens,
   article/comment tests stay green with storage-only edits (list each with reason); new tests for reply cascade, hide/unhide
   conditional updates, string comment refs through report → case → audit (Cassandra row has `comment_ref`), import idempotency and
   remapping, grep proof that only the import reads SQL `comments`.
7. Docs: `docs/database/nosql-local.md` (comments collection, Cassandra column note), `GATE-REPORT-9.5-2.md` in this folder.

## Hard rules / isolation

Mongo `vietfeed_test`, Redis DB15 guard, Cassandra keyspace `vietfeed_test`, SQLite `:memory:`. Never touch dev data (Mongo
`vietfeed`, Redis DB0/1/2, Cassandra `vietfeed`); no FLUSHALL, no migrate/seed/new commands against dev, no container restarts.
Sequential suites. Never edit existing migrations. No new dependencies. Owner WIP untouched.

## Non-goals

Bookmark/Boost/ArticleUnlock/SourceFetchLog (later slices), dropping SQL tables, comment rate limiting, product changes.

## Stop conditions

A 9a/9b rule that cannot hold; need to edit owner WIP; product change; failure not fixable in scope; container restart or OOM.
