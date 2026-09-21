# Start Here — Implementing VietFeed NoSQL + StoryLens

## Canonical source

Read and follow:

1. `docs/specs/vietfeed-nosql-storylens.md` — canonical implementation source of truth.
2. `docs/planning/technical-gates.md` — executable gates and fallback conditions.
3. `docs/architecture/database-feature-map.md` — detailed ownership rationale.

Supporting research and demo documents do not override the canonical spec.

## Copy-paste master implementation prompt

```text
Implement VietFeed NoSQL + StoryLens in this repository using
docs/specs/vietfeed-nosql-storylens.md as the canonical source of truth.

Before changing code:
1. Read the canonical spec completely.
2. Read docs/planning/technical-gates.md and
   docs/architecture/database-feature-map.md.
3. Inspect the current git status and preserve all unrelated user changes.
4. Confirm the current ticket/slice and its acceptance criteria.
5. Work on a dedicated branch and keep the slice independently testable.

Implementation rules:
- Build vertical slices; do not implement one entire database in isolation.
- Use MongoDB for canonical current state, Cassandra for query-first history,
  Neo4j for relationship topology, and Redis for ephemeral realtime state.
- Generate canonical UUIDv7 IDs in Laravel before persistence.
- Do not add SQLite, MySQL, PostgreSQL, or another runtime database.
- Do not put every feature on all four databases.
- Controllers and workers must call domain modules rather than database
  clients directly.
- Cross-store consumers must be idempotent, version-aware, retryable, and
  replayable, with visible pending/failed state.
- Never block canonical Story rendering on Neo4j, Cassandra, Redis
  projections, or AI output.
- Never commit secrets, raw Access/Refresh Tokens, or real credentials.
- Preserve required existing behavior; note that Report is a repair plus
  migration task, not a known-good baseline.
- Write tests against public behavior and run the smallest relevant tests
  throughout the work.

Stop conditions:
- Do not begin broad migration until Docker infrastructure and Cassandra
  compatibility gates pass.
- Timebox the Cassandra/PHP spike to half a day. If it fails, record the
  evidence and use the narrow Java/.NET adapter fallback defined in the spec.
- If a requested implementation contradicts the canonical spec, stop and
  request an explicit spec/ADR update instead of guessing.

At completion of each slice, report:
- acceptance criteria satisfied;
- tests and health checks run;
- database ownership affected;
- migrations/config/env changes;
- failure/recovery behavior verified;
- remaining blockers and the next unblocked slice.
```

## First implementation prompt

Use this for the first coding session:

```text
Implement only the first infrastructure and compatibility slice from
docs/specs/vietfeed-nosql-storylens.md.

Deliverables:
1. A Docker Compose environment for MongoDB, Cassandra, Neo4j, and Redis,
   all single-node and resource-capped for an Apple M1 machine with 8 GB RAM.
2. Persistent development volumes, explicit ports, health checks, and safe
   local-only credentials sourced from environment variables.
3. Documented commands to start, inspect, stop, reset, and seed the stores.
4. A single health command or script that reports readiness for all four DBs.
5. Connection configuration usable by Laravel without committing secrets.
6. DBeaver connection notes plus native CLI verification commands.
7. A half-day Cassandra compatibility spike using the actual PHP 8.4/Laravel
   runtime: connect, create/query one query-first timeline table, append an
   event, read its partition, and verify error/reconnect behavior.
8. A written spike result. If the PHP integration fails within the timebox,
   stop and propose the narrow Java/.NET adapter contract; do not begin a
   Laravel rewrite.

Constraints:
- Preserve all existing unrelated worktree changes.
- Do not migrate application features in this slice.
- Do not add a fifth runtime database.
- Do not claim success until all four containers run together while Laravel
  and the browser remain responsive.
- Verify resource consumption with Docker statistics and record the chosen
  limits.

Tests/verification:
- Compose configuration validation.
- Container health/readiness checks.
- One native smoke query for each database.
- Cassandra integration test or a documented failed-spike result with exact,
  redacted evidence and the approved fallback decision.
```

## Planned implementation sequence

1. Docker infrastructure and Cassandra compatibility.
2. MongoDB/Redis/Neo4j application foundations.
3. Four-database StoryLens tracer bullet with failure/recovery.
4. Content, feed, search, interactions, Reading Pass, and Source Health.
5. Report repair, moderation, RBAC, and account lifecycle.
6. Stripe-to-Mongo billing.
7. First-party API AT/RT.
8. AI enrichment, human review, and evaluation.
9. SQL removal, rebuild tests, hardening, and demo rehearsal.

Do not skip directly to AI or broad CRUD migration before the first three steps establish executable evidence.
