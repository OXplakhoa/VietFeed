# Gate 9.5 slice 5 — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **PASS_WITH_NOTES, conditional on MAJOR-1**. Integrity 60/60 OK, 35 deleted paths absent. Runtime boot (provider override
wins, config:cache clean), idempotent index migrations on rerun, cache-broker reset tokens (hashed, 3600 s TTL, 60 s throttle),
failed jobs/batches on Mongo, Redis defaults, deletions with no leftover references, 17-collection test wipe, no-SQL guard, 16 deleted
tests all importer/SQL-only, composer.lock unchanged: sound.

- MAJOR-1: with mongodb as the default connection, `migrate:fresh` / `db:wipe` / `migrate:reset` drop the whole Mongo database
  (laravel-mongodb Schema\Builder::dropAllTables → database drop). docs/demo/CHEAT-SHEET-THUYET-TRINH.md:74-89,355 and
  docs/demo/SCRIPT-THUYET-TRINH.md:177 (and a historical note in nosql-local.md:75) instruct `DB_DATABASE=vietfeed_new` +
  `migrate:fresh --seed`, live in the presentation; DB_DATABASE is now ignored. No confirmation outside production.
- MINOR-1: first dev `migrate` puts all index migrations in one batch; one rollback drops unique indexes; some down() throw if missing.
- MINOR-2: reset tokens can be evicted by Redis allkeys-lru (fail-closed; undocumented).
- MINOR-3: DB15 cache keys (reset tokens/throttle) not flushed between tests → possible flake on repeated faker emails.
- NIT-1: homepage excluded from the query-count equality; not an N+1 (≤17 reads) — use assertLessThanOrEqual(17).
- NIT-2: stale SQL mentions in docker-compose.yml comment, docs/demo/api-token-demo.md, storylens-progressive-rendering.md.
- NIT-3: runbook could start from composer install / .env / key:generate / compose up / npm build.
