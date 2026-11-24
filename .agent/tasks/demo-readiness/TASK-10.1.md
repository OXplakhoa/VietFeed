# Gate 10.1 — Demo wiring, live papercuts, deterministic synthetic workload

Canonical sources: `docs/planning/technical-gates.md` Gate 10 (bullet 1 "Frozen real-RSS dataset and deterministic synthetic
workload are versioned"); `docs/specs/vietfeed-nosql-storylens.md` (synthetic workload: labelled, fixed seed, never presented as
real traffic); `GAP-ANALYSIS.md` in this folder (Gemini 4 Neon, read-only) §1.1, §1.3, §4, §5 items 1, 2, 8 and proposed slice 1.

One slice, one branch, one PR: branch `demo/readiness-wiring`, base `main` `5ffcd58`. **Control stops before merging.**

## Required outcomes

1. **Scheduler wiring** (`routes/console.php`): the StoryLens timeline and graph consumers, `moderation:audit consume` and a SAFE
   form of `moderation:repair` run on the schedule with `withoutOverlapping` (and `runInBackground` where long), so a live demo
   converges from `pending` without manual commands. Read each command first: never schedule anything that deletes data or needs
   `--force`-style operator judgement; if `moderation:repair` only converges idempotently, schedule it at a sensible interval,
   otherwise schedule its dry-run/inspect form or leave it out and document why. Document every entry in
   `docs/database/nosql-local.md`.
2. **Fixture articles open on `/articles/{slug}`**: today `storylens ingest` stores only `source_snapshot`/`category_snapshot`, so
   fixture articles 404 (Article page requires active Source + Category). Make ingest resolve canonical `source_id`/`category_id`
   when a matching active Source/Category exists (match on stable fields such as feed URL / slug — inspect the fixture and the
   models, choose, document), and keep the current behaviour otherwise. Do NOT change the Article page access rules and do NOT edit
   `database/seeders/SourceSeeder.php` (owner WIP). Existing ingested data is not migrated (document how to re-ingest).
3. **Reports on anonymized comments** (Gate 9.7b follow-up): hide "Báo cáo" for comments with `account_deleted`, and
   `ModerationService::report` refuses them server-side (clear Vietnamese message).
4. **Deterministic synthetic workload**: versioned `tests/Fixtures/synthetic-workload-v1.json` (manifest: `synthetic: true`,
   fixed seed, number of synthetic users/events, time window) and a command `php artisan demo:synthetic {seed|inspect}
   [--seed=] [--dry-run]` that creates clearly labelled synthetic reader accounts (e.g. `synthetic+NN@demo.invalid`, name prefix
   "Synthetic"), and drives bookmarks / boosts / Reading Pass unlocks THROUGH the existing services so the normal Redis streams →
   Cassandra projections and Redis trending are exercised (no direct writes that bypass the pipeline). Same seed ⇒ same users, same
   events, same resulting top-K trending and Cassandra row counts. Every synthetic document carries `synthetic: true` where the
   model allows; the command refuses to run when `APP_ENV=production`; idempotent re-run (no duplicates). It must work against the
   existing real/fixture articles (pick articles deterministically, e.g. by sorted id).
5. **Docs**: `docs/database/nosql-local.md` (scheduler table, ingest resolution, synthetic workload: purpose, labels, commands,
   how to remove it), `GATE-REPORT-10.1.md` here (decisions, verification with exit codes, follow-ups).

## Tests — "vừa đủ" (owner rule)

At most 4 new test methods total, one per real risk: synthetic workload is deterministic (two runs on fresh stores → identical
top-K and Cassandra counts) and idempotent; fixture ingest resolves ids when Source/Category exist and still works when not;
report refused on anonymized comment (extend an existing moderation test if possible); scheduler entries exist with overlap
protection (extend an existing console/schedule test if one exists). Prefer extending existing tests.

## Hard rules / isolation

Never touch dev data (Mongo `vietfeed`, Redis DB0/1/2, Cassandra `vietfeed`, Neo4j nodes not created by tests); tests only on
isolated stores (phpunit.xml forces `vietfeed_test`; Redis DB15 guard). Do NOT run `demo:synthetic`, ingest, migrations, seeders or
any mutating command against dev. No FLUSHALL, no `migrate:fresh`/`db:wipe`, no docker start/stop. Never read, print or edit
`.env`. No real network calls. No new Composer/npm dependencies. Never edit existing migrations. Owner WIP untouched: `CLAUDE.md`,
`database/seeders/SourceSeeder.php`, `.agent/tasks/{infra-cassandra-spike,mongo-foundation,neo4j-foundation,redis-cutover,
claude-code-handoff}/`, `.agent/handoff/`, `.agent/migration-pc/`, `.agent/control/`, `.pi/`, `docs/agents/`, `pi-warden.md`.
Do not commit; control commits.

## Non-goals (later slices)

Failure-fallback tests for Google/Mail/Ticker and the Stripe test-mode smoke (10.2); the ≥100-article evaluation dataset, human
annotation and claim review (10.3, needs human annotators); runbook rewrite, presentation docs, clean-reset script and timed
rehearsals (10.4); building Entity/Aspect graph features promised by the runbook (owner decision pending).

## Stop conditions

Product change beyond the above; owner WIP edit; need to change `.env`; failure not fixable in scope; container restart or OOM;
any new dependency.

## Owner decisions for later Gate 10 slices (2026-11-24)

- Runbook: amend the runbook and presentation docs to match the shipped features (Story–Article–Source–Category graph, timeline,
  reviewed AI brief); no Entity/Aspect/User-recommendation work before the demo.
- Annotation: no second annotator is available. Plan (control proposal, pending owner confirmation): the owner annotates alone,
  blind to system output; ~20 items re-annotated after ≥ 2 days to measure self-consistency; AI claim review done by the owner;
  the evaluation report states the single-annotator limitation explicitly (no inter-annotator agreement).
