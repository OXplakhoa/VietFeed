# Gate 9.5 slice 4 — Source fetch history (SourceFetchLog) on Cassandra

Canonical sources: `docs/planning/technical-gates.md` Gate 9.5, `docs/specs/vietfeed-nosql-storylens.md` §9.2 (Cassandra: Source
fetch history, query-first), §13, `docs/architecture/database-feature-map.md` (Source CRUD/prestige tier: MongoDB current config;
fetch attempts are time-series in Cassandra; mandatory demo query "health of one RSS source over a day/week"; the current Source
health summary fields stay on the Mongo Source document).

One slice, one branch, one PR: branch `cutover/source-history`, base `main` `958640b`.

## Recon facts (control, 2026-11-18)

- `App\Models\SourceFetchLog` (SQL `source_fetch_logs`, `source_id` string since slice 1): fields type, status, http_status, items
  found/created/updated, valid/invalid, missing_image_count, date_parse_error_count, duplicate_count, error_type, error_message,
  duration_ms, started_at, finished_at. Writers: `App\Services\Rss\FeedIngestionService`, `App\Jobs\FetchSourceFeedJob`,
  `Admin\SourceController` (manual fetch/test actions create logs via `$source->fetchLogs()->create`). Readers: `Admin\SourceController`
  Source Health (global "recent 20" table + per-source last 5 via ROW_NUMBER from slice 1) and Source show page (last 15 for one
  source), `Source::fetchLogs()`, `App\Content\ConfigSqlImport` (remaps source ids). The Mongo Source document already carries the
  current health summary (`last_*`, `consecutive_failures`, `health_status`).
- Patterns to reuse: `CassandraClient` (keyspace guard), schema commands and idempotent inserts in `ModerationAudit`,
  `InteractionActivity`, Reading Pass history; streams via `StreamConsumer` where async is needed.

## Required outcomes

1. Cassandra tables designed per query: (a) one source's attempts over time (partition by source + time bucket, e.g. day; clustering
   started_at DESC), (b) global recent attempts across sources for the health page (bounded partition, e.g. per day bucket, clustering
   started_at DESC), each row with a UUIDv7 attempt id; idempotent inserts; TTL/retention documented (choose a sensible retention).
2. Writers record each attempt directly (synchronous write is fine) or via a stream — choose and justify; a Cassandra failure must
   NEVER fail or slow down a fetch materially (log without payload; the Mongo Source summary is still updated as today).
3. Readers: Source Health page (global recent 20, per-source last 5), Source show page (last 15) and any other view read Cassandra,
   handling Cassandra-down with an explicit "history unavailable" state instead of a 500. No SQL reads of `source_fetch_logs` on runtime
   paths. Add the demo query "one source over a day/week" as an artisan command (e.g. `sources:history --source= --days=`).
4. Data move: idempotent `sources:import-history-sql` copying SQL fetch logs into Cassandra (deterministic attempt ids from the legacy
   id so reruns do not duplicate), counts printed; runs once (refuse rerun without `--force`, like slices 3a/3b); SQL table left unused.
   Update `ConfigSqlImport` only if it must stop touching `source_fetch_logs` (keep slice 1 behaviour working).
5. Tests on isolated stores (Cassandra `vietfeed_test`, Mongo `vietfeed_test`, Redis DB15 guard, SQLite `:memory:`): writes on success /
   warning / failure fetches, Cassandra-down fetch still succeeds, health/show pages read Cassandra and degrade gracefully, bucket
   boundaries, import idempotency, grep proof that only the import reads SQL `source_fetch_logs`. Existing tests green with
   storage-only edits (listed).
6. Docs: `docs/database/nosql-local.md` (tables, retention, cqlsh queries for the demo), `GATE-REPORT-9.5-4.md`.

## Hard rules / isolation

Never touch dev data (Mongo `vietfeed`, Redis DB0/1/2, Cassandra `vietfeed`); no FLUSHALL, no migrate/seed/new commands against dev,
no container starts/restarts, no real RSS network calls in tests. Sequential suites. Never edit existing migrations. No new
dependencies. Owner WIP untouched (`database/seeders/SourceSeeder.php` must keep working unchanged).

## Non-goals

Dropping SQL tables/migrations (slice 5), changing fetch logic or health thresholds, UI redesign.

## Stop conditions

Product change; owner WIP edit; failure not fixable in scope; container restart or OOM.
