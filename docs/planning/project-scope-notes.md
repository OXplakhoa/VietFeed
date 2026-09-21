# VietFeed NoSQL — Scope notes

> Decision history supporting `docs/specs/vietfeed-nosql-storylens.md`. The canonical spec takes precedence if wording differs.

## Known baseline issues

### Report and moderation

- The existing **Report** feature is incomplete and currently broken.
- It must not be treated as a working feature that only needs a database port.
- NoSQL scope includes both:
  1. finishing/fixing the user report flow;
  2. mapping its current state to MongoDB, audit history to Cassandra, and the admin review queue/counters to Redis.
- Neo4j involvement is optional and should only be added for a concrete relationship query, such as detecting repeated reports across related users/comments. Do not involve Neo4j merely to claim all four databases are used by this feature.
- Acceptance tests for Report must be defined from expected behaviour rather than copied from the current implementation.

## Confirmed decisions

### Local database runtime and inspection

- MongoDB, Cassandra, Neo4j, and Redis all run locally through Docker containers; none of the four databases is installed as an application runtime dependency directly on macOS.
- Use single-node, development-sized containers with explicit CPU/memory limits suitable for the target Apple M1 machine with 8 GB RAM.
- DBeaver is the preferred GUI for inspecting connections, browsing data, and running simple database queries during development and demonstration.
- DBeaver is an operator/developer tool only. Laravel and background workers connect directly to the databases through configured drivers/adapters; application correctness must not depend on DBeaver being open.
- Keep database-native command-line tools available for health checks, scripts, reproducible tests, and fallback verification when a DBeaver driver or edition does not expose a database capability.

### StoryLens consistency and progressive rendering

- MongoDB owns the canonical current Article/Story and makes it available immediately after ingestion.
- Cassandra timelines, Neo4j graph projections, and Redis ranking/cache may converge asynchronously; the target local-demo lag is approximately five seconds under normal conditions.
- AI-derived StoryLens sections run independently from those technical projections and may take longer. They require a bounded timeout plus deterministic/extractive fallback rather than an indefinite loading state.
- A downstream projection failure must not make the canonical Article/Story unavailable. Consumers must be idempotent, retry failures, expose failed/pending status, and support replay/rebuild.
- The UI uses progressive rendering: show canonical content first, then replace clearly labelled loading/processing states as derived sections become ready.
- The first render may show the provisional Story title, article titles/excerpts, original links, source names and prestige tiers, publication timestamps, source count, and a basic publication-order timeline directly from persisted MongoDB data.
- Source-attributed deterministic data is reproducible evidence, not a claim that the source is objectively correct. The UI should attribute claims to their sources.
- Derived content must have explicit `pending`, `partial`, `ready`, or `failed` state. Never present a partial AI summary or incomplete graph as a completed result.
- Admin can inspect projection status and retry failed processing.

### Account deletion and administrative sanctions

- User-initiated account deletion and admin sanctions are separate lifecycles.
- A user-initiated deletion immediately revokes access and sessions, cancels an active Stripe subscription, and enters a 30-day recoverable retention period.
- After the retention period, a scheduled application worker purges personal/current data from MongoDB and Redis, removes the Neo4j User node/relationships, and anonymizes Cassandra history that still has analytical value.
- Purge progress is tracked as an idempotent, retryable workflow rather than a synchronous four-database request.
- An admin-sanctioned or revoked account does not automatically enter the 30-day deletion workflow. It remains available for moderation evidence and appeals and follows a separate retention/anonymization policy.

### Roles and permissions

- MVP has three fixed roles: `user`, `admin`, and `super_admin`.
- `super_admin` always has every permission and is the only role allowed to grant/revoke admin permissions, create/revoke admins, and perform protected account/system operations.
- Each `admin` receives permissions directly from a fixed permission catalog through a Super Admin UI. MVP does not include creation of arbitrary new role types.
- Authorization is enforced server-side with Laravel Gates/Policies and `can` middleware; hiding controls in the UI is not an authorization boundary.
- MongoDB owns current roles and permission grants. Cassandra may record the append-only authorization audit trail. Redis and Neo4j are not involved merely to make the feature use more databases.
- An admin cannot grant permissions to itself, modify a Super Admin, or remove/demote the final Super Admin.

### First-party API authentication

- Keep the server-rendered Blade website on Redis-backed Laravel sessions. Do not rewrite the existing web UI as an SPA merely to use tokens.
- Add a narrow `/api/v1` surface protected by VietFeed-issued Access Token/Refresh Token pairs and demonstrate it with `curl`.
- Use opaque cryptographically random tokens. Redis stores only token hashes, token-family state, and TTL; raw tokens are returned once to the client and must never be persisted or logged in plaintext.
- Configure Access Token and Refresh Token TTL values in **seconds** through `.env`; committed example files contain variable names and safe defaults/placeholders only, never credentials.
- Rotate the Refresh Token on every successful refresh. Reuse of a rotated token revokes the entire token family.
- Logout, account sanction/revoke, password change, and user-initiated deletion revoke all relevant API token families immediately.
- Normal configuration keeps Refresh Token TTL greater than Access Token TTL. Failure tests may intentionally reverse the values to prove that a still-valid Access Token continues until its own expiry but cannot be refreshed after the Refresh Token expires.
- Minimum protected API/demo surface: login, refresh, logout, current user, one Story read endpoint, and one authenticated interaction endpoint.

### Demo duration and structure

- Prepare a golden-path demo of approximately 20 minutes and optional modules extending it to approximately 30 minutes.
- Demonstrate primary features and one visible/queryable capability per database rather than clicking every CRUD route.
- Keep a reduced 15-minute path that still shows StoryLens, all four database roles, one interaction, and one failure/recovery case.
- Use deterministic replay data and scripted `curl` requests so the demo can be repeated reliably.
- Internet is expected in the demo room and a personal 5G hotspot is available as a network fallback, so Google OAuth, Stripe Test Mode, Gemini and live RSS may be demonstrated live.
- The core StoryLens path must nevertheless remain usable when an external service fails: frozen replay data, persisted validated summaries/extractive fallback, and local test accounts prevent one network dependency from ending the demo.

### Real content and synthetic workload data

- Article and Story evidence must come from real RSS sources already supported by VietFeed. Live ingestion may fetch current news; frozen replay fixtures are timestamped snapshots of previously fetched real RSS content with source provenance, not fabricated articles.
- Synthetic data is allowed for interaction/history workloads such as views, unlocks, bookmarks, boosts and source-fetch events so Cassandra partitions and Redis trending/rebuild behaviour can be exercised at a meaningful volume.
- Synthetic records must be clearly labelled in seed metadata, documentation, UI/demo commentary and benchmark reports; never imply they represent real VietFeed traffic.
- Use deterministic random seeds so the same workload, spikes and expected query results can be reproduced.

### Human evaluation dataset

- Two non-coding team members can each spend approximately 2–4 hours independently annotating the same real-RSS evaluation set using `docs/evaluation/human-annotation-guide.md`.
- Use a training round and an independent calibration round before the 100–200 article main annotation batch.
- Preserve both raw annotation sheets and reconcile disagreements into a separately versioned gold dataset; never overwrite raw labels after seeing algorithm predictions.
- Review AI output claim-by-claim on approximately 15–20 multi-source Stories using evidence IDs rather than general plausibility or personal knowledge.

### SQL-to-NoSQL migration and existing data

- Migration must preserve required behaviour and user-visible features, but it does not need to preserve the existing local SQL dataset record-for-record.
- Before cutover, create one read-only backup/export of the current SQL database for recovery and comparison; do not keep SQL as a hidden runtime dependency.
- Bootstrap MongoDB and the other stores from fresh seed/configuration data, create a new initial Super Admin securely, and re-ingest articles from RSS/replay fixtures.
- Keep deterministic frozen RSS/replay fixtures so tests and the final demo do not depend on old database contents or live feeds.
- Do not spend project time building a production-grade SQL-to-NoSQL ETL for stale, reproducible RSS content.

### Cross-database identifiers

- Laravel generates a canonical UUIDv7 before the first persistence operation for every cross-store aggregate and domain event.
- The same ID is stored as the MongoDB document `_id`, Cassandra entity/event reference, Neo4j node business `id`, Redis key/event reference, and external metadata such as Stripe `user_id`.
- MongoDB ObjectIds, Neo4j internal node IDs, and database-local generated IDs must not be used as cross-store business identifiers.
- Public article/story routes may continue to use readable slugs. UUIDv7 is the internal identity, not a requirement for public URLs.
- Timeline ordering uses an explicit timestamp plus an event ID tie-breaker; do not depend on UUID ordering as the only chronology field.

### Billing and Stripe

- Billing must remain a real end-to-end integration using **Stripe Test Mode**; a local simulated subscription is not sufficient for the final project.
- Preserve the existing user-visible flow: Pricing -> Stripe Checkout -> signed webhook -> Pro activation -> Reading Pass benefits -> Stripe Billing Portal.
- Stripe is authoritative for the external payment/subscription lifecycle. MongoDB is the only project database used by Billing and owns the application's local customer/subscription projection after the SQL runtime is removed.
- Stripe webhook processing must be idempotent. Store each processed Stripe event ID under a MongoDB unique index; do not put Redis, Cassandra, or Neo4j on Billing's critical path merely to involve more databases.
- A billing audit timeline in Cassandra is optional future enrichment, not part of the Billing MVP or its source of truth.
- Replace or isolate Laravel Cashier's SQL-backed persistence instead of keeping hidden SQL tables. Existing screens, Stripe configuration, and user flow may be reused, but the persistence/integration layer requires migration.

## Terminology

- **Existing features** means features present before StoryLens. Do not describe them as deprecated or safe to remove.
- **StoryLens** is the innovation feature; it does not replace the existing VietFeed CRUD, authentication, feed, interaction, moderation, and administration features.
