# VietFeed NoSQL — Technical gates before full implementation

> Executable gate checklist for `docs/specs/vietfeed-nosql-storylens.md`. These gates validate the spec; they do not redefine its scope.

These gates require executable evidence, not another product decision. Stop a spike when its timebox is exhausted, record the result, and use the documented fallback instead of silently expanding scope.

## Gate 1 — Reproducible local infrastructure

- All four databases start together through Docker on the target M1/8 GB machine with single-node, demo-sized CPU/memory limits.
- Laravel, queue consumers and the browser remain responsive.
- One health command reports MongoDB, Cassandra, Neo4j and Redis readiness.
- Docker volumes and a deterministic reset/seed command are documented.
- DBeaver connection profiles can inspect all supported stores and execute the simple queries selected for development/demo; native CLI checks remain the reproducible verification path.

## Gate 2 — Cassandra integration, half-day timebox

- Connect from the actual PHP 8.4/Laravel runtime to the selected Cassandra Docker version.
- Create one query-first table, append an event and read a timeline partition in an integration test.
- Verify reconnect/error handling.
- If the PHP path fails within the agreed half-day spike, use the narrow Java/.NET Cassandra adapter; do not rewrite the Laravel application.

## Gate 3 — MongoDB Laravel migration

- Install/configure the official Laravel MongoDB integration.
- Prove User registration/login, a canonical Article/Story CRUD path and UUIDv7 route/reference handling.
- Replace one representative SQL join/raw aggregate/`withCount` path with Mongo-native documents/counters.
- Confirm the app can boot and run the slice without SQLite/MySQL.

## Gate 4 — Neo4j PHP integration

- Connect through the chosen PHP client.
- Upsert canonical UUIDv7 nodes/edges idempotently.
- Run one multi-hop Story–Article–Source–Entity query and one retry test.

## Gate 5 — Redis infrastructure and recovery

- Redis-backed Laravel sessions, cache and queue work without SQL tables.
- Stream consumer retry/dead-letter and Sorted Set trending are demonstrable.
- Flush/rebuild test proves Redis is not the only durable holder of business data.

## Gate 6 — First four-database vertical slice

- Frozen real-RSS fixture creates canonical MongoDB Article/Story.
- Redis dispatches processing.
- Cassandra timeline and Neo4j graph converge asynchronously.
- First render works before projections finish, and one failed consumer can recover through retry/replay.

## Gate 7 — Stripe-to-Mongo billing

- Stripe Test Checkout, signed webhook, idempotent event receipt, local subscription projection, Reading Pass entitlement and Billing Portal work without Cashier SQL tables.
- Duplicate and out-of-order webhook handling has tests.

## Gate 8 — First-party API token flow

- Opaque AT/RT hashes live in Redis with second-based TTL configuration.
- Login, refresh rotation, logout, reuse detection, expiry and account-wide revocation pass integration tests.
- `curl` failure demo is deterministic and does not commit/log raw credentials.

## Gate 9 — Report repair before migration sign-off

- Reproduce and diagnose the broken baseline Report flow before claiming feature parity.
- Define expected user report, admin review, sanction and appeal behaviour with acceptance tests.
- Then migrate current case state to MongoDB, review queue to Redis and audit history to Cassandra.
- Delivered in two parts (owner decision 2026-11-17): **9a** repairs the flow and pins the behaviour with acceptance tests on
  the existing storage; **9b** performs the storage migration with 9a's tests as the regression net.

## Gate 9.5 — Final SQL cutover (before Gate 10)

Spec §13 items 6–8: the final cutover succeeds only when the application boots, tests and demos without a SQLite/MySQL runtime.
Added by owner decision 2026-11-17, because no earlier gate moves the remaining SQL models.

- Move the remaining SQL-backed state to its planned store (`docs/architecture/database-feature-map.md`): `Category` and
  `Source` → MongoDB; `Comment` (with moderation hide/snapshot fields) → MongoDB; `Bookmark`, `Boost` → MongoDB current state with
  Cassandra activity events; `ArticleUnlock` → Redis counters with Cassandra unlock history; `SourceFetchLog` → Cassandra.
- Replace the remaining SQL joins, `withCount`, pivots, foreign keys and transactions with Mongo documents, indexes, counters or
  application workflows; no cross-store `whereHas`/`withCount`.
- Remove SQL migrations, the SQL default connection and SQLite from the test runtime; `composer run test` and the demo run with
  only MongoDB, Cassandra, Neo4j and Redis.
- Delivered as small slices (one PR each, e.g. configuration, comments, interactions, source history, SQL removal); each slice
  keeps the full suite green and is reviewed independently.
- Seed configuration, Sources and Categories into MongoDB; stale local SQL records are not migrated (spec §13 item 2).

## Gate 9.6 — StoryLens AI brief with human review

Spec §12.1 item 9 and §12.2 items 3–5; added by owner decision 2026-11-22.

- AI brief generated asynchronously behind a provider seam (Gemini primary; OpenAI-compatible and fake drivers), with timeout,
  structured validation, evidence IDs and a deterministic extractive fallback; the Story page never waits for AI.
- Current brief state on the MongoDB Story document, output versions in MongoDB `story_briefs`.
- Public pages show an AI brief only after human acceptance and only for the current Story version; partial or unsupported
  output is never published.
- Admin review (accept/reject/retry) and a minimal evaluation command; cluster merge/split stays a follow-up.

## Gate 9.7 — RBAC and account lifecycle

Spec §7 stories 18–21, 24, 25; added by owner decision 2026-11-22.

- Fixed admin permissions (content, moderation, operations, Story review, user management) granted and revoked by a Super Admin;
  the final Super Admin cannot be deleted or demoted; permission checks never depend on Redis cache.
- Account deletion revokes access immediately, keeps a 30-day recovery period, then purges, including Stripe cancellation and
  Neo4j/Cassandra/Redis cleanup.

## Gate 10 — Evaluation and final demo readiness

- Frozen real-RSS dataset and deterministic synthetic workload are versioned.
- Human annotation/reconciliation and AI claim review are complete.
- Core 20-minute, reduced 15-minute and extended 30-minute runbooks have been rehearsed from a clean reset.
- External-service failure fallbacks are verified, not merely described.
