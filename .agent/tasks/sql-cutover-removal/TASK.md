# Gate 9.5 slice 5 (final) — Remove the SQL runtime and SQLite from tests

Canonical sources: `docs/specs/vietfeed-nosql-storylens.md` §13 items 6–8 ("Final cutover succeeds only when the application boots,
tests, and demos without SQLite/MySQL runtime"), §9.4 (Redis: sessions, rate limits, reset tokens with TTL), `docs/planning/
technical-gates.md` Gate 9.5, `docs/architecture/database-feature-map.md`.

One slice, one branch, one PR: branch `cutover/sql-removal`, base `main` `fbddbf7`. **Control stops before merging this PR (owner
approval required).**

Owner decision (2026-11-18): remove all legacy SQL import commands. The tag `pre-sql-removal` (= fbddbf7, pushed) is the last version
with the SQL runtime and importers; legacy data is migrated by checking out that tag, running the imports, then upgrading.

## Recon facts (control, 2026-11-18)

- All domain models are on MongoDB after slices 1–4. Remaining SQL dependencies: Laravel's `migrations` repository (default
  connection), failed jobs (`queue.failed.driver` `database-uuids`), password reset tokens (`auth.passwords.users.table`
  `password_reset_tokens`, database broker), job batches table, config defaults (`DB_CONNECTION` sqlite, `SESSION_DRIVER` database,
  `CACHE_STORE`/`QUEUE_CONNECTION` database fallbacks), 22 SQL migrations under `database/migrations`, `database/database.sqlite`, and the
  test runtime (`phpunit.xml` DB_CONNECTION=sqlite + DB_DATABASE=:memory:, `RefreshDatabase` in many tests, SQL-specific assertions such
  as query listeners on the default connection).
- Importers to delete: `ConfigSqlImport`, `CommentSqlImport`, `SqlImport` (moderation), `InteractionSqlImport`, `ReadingPassSqlImport`,
  `SourceHistorySqlImport` + their commands, the processing-page "legacy SQL" warnings, and their tests.
- Mongo is standalone; Mongo index migrations exist (`*_create_mongodb_*`). laravel-mongodb supports a Mongo-backed migrations
  repository and failed-job provider — verify against the installed version before relying on it.

## Required outcomes

1. Default database connection `mongodb`; the app boots, serves every page and runs every command with **no** SQL driver configured
   (no sqlite/mysql connection required; `pdo_sqlite`/`pdo_mysql` not needed at runtime). Migrations run on MongoDB (Mongo migrations
   repository); the remaining migrations are only the Mongo index migrations (plus any new ones). SQL migrations are deleted.
2. Password reset tokens in Redis with TTL (or Mongo with TTL index — justify; feature map prefers Redis), keeping the existing reset
   flow and throttling. Failed jobs stored in MongoDB (or Redis) with the existing `queue:failed`/`queue:retry` commands working. Job
   batches disabled or Mongo-backed (only if used). Sessions/cache/queue default to Redis in config and `.env.example`.
3. Delete all legacy SQL importers, their commands, tests and processing-page warnings; docs point to the `pre-sql-removal` tag for
   legacy migration (`docs/database/nosql-local.md` + `GATE-REPORT-9.5-5.md`).
4. Tests: `phpunit.xml` no longer sets sqlite; `RefreshDatabase`/SQL helpers replaced by the Mongo/Redis/Cassandra isolation already used
   (Mongo `vietfeed_test` wipe, Redis DB15 guard, Cassandra `vietfeed_test`); assertions that referenced the SQL connection rewritten
   to their store; a test that fails if any query hits a SQL connection during the suite (e.g. a listener or forbidding the PDO
   connection). Full `composer run test` green; test count may drop only by the deleted importer tests (list them).
5. Composer: no SQL-only packages are required; `composer check-platform-reqs` passes without pdo_sqlite/pdo_mysql (state what
   remains as transitive requirements, if any). No new dependencies unless needed for 2 (justify).
6. A clean-reset proof: on isolated stores, from empty Mongo `vietfeed_test` / Redis DB15 / Cassandra `vietfeed_test`: `migrate`,
   schema/bootstrap commands, seeders, and a smoke of key pages, with `DB_CONNECTION` unset and no `database/database.sqlite` — documented
   command list for the Gate 10 runbook.
7. Docs: update `docs/database/nosql-local.md`, `.env.example`, `CLAUDE.md`-style command docs are owner WIP — do NOT edit
   `CLAUDE.md`; write the new setup commands in `docs/database/nosql-local.md` and the gate report.

## Hard rules / isolation

Never touch dev data (Mongo `vietfeed`, Redis DB0/1/2, Cassandra `vietfeed`), never edit the active `.env`, no FLUSHALL, no migrate/seed
against dev, no container starts/restarts. Sequential suites. Owner WIP untouched (incl. `CLAUDE.md`, `database/seeders/SourceSeeder.php`,
which must keep working). Never edit Mongo index migrations that already ran on dev except to keep them idempotent.

## Non-goals

Gate 10 runbooks, the AI summary feature, the Cassandra read-timeout follow-up, UI changes.

## Stop conditions

A product change; owner WIP edit; something that needs the active `.env` changed (report the exact change for the owner instead);
failure not fixable in scope; container restart or OOM.
