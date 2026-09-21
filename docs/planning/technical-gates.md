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

## Gate 10 — Evaluation and final demo readiness

- Frozen real-RSS dataset and deterministic synthetic workload are versioned.
- Human annotation/reconciliation and AI claim review are complete.
- Core 20-minute, reduced 15-minute and extended 30-minute runbooks have been rehearsed from a clean reset.
- External-service failure fallbacks are verified, not merely described.
