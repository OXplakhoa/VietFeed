# VietFeed NoSQL + StoryLens — Canonical Implementation Spec

**Status:** Approved for implementation  
**Audience:** Developers, reviewers, report authors, and implementation agents  
**Application:** Laravel 13 / PHP 8.3+ / Blade  
**Runtime databases:** MongoDB, Apache Cassandra, Neo4j, and Redis, all running through Docker  

## 1. Source-of-truth policy

This document is the canonical implementation source of truth for the VietFeed NoSQL upgrade and StoryLens feature.

When documents disagree, use this precedence:

1. This canonical spec.
2. A newer accepted ADR under `docs/adr/` that explicitly supersedes part of this spec.
3. `docs/planning/project-scope-notes.md` and `docs/architecture/database-feature-map.md`.
4. Technical, evaluation, and demo supporting documents.
5. Exploratory research under `docs/research/`.

Technical spikes may choose a concrete driver or adapter, but they may not silently change product scope, database ownership, security contracts, or failure semantics. Record such changes in an ADR and update this spec.

## 2. Problem statement

VietFeed currently aggregates RSS articles into an article-first Laravel application backed by a relational database. The course project requires MongoDB, Cassandra, Neo4j, and Redis to be used for capabilities suited to each database. The upgraded project must preserve the existing user-facing and administrative features, remove SQL from runtime, and add a defensible innovation rather than four disconnected database demos.

Breaking-news readers also face repeated coverage across many publishers. Opening several nearly identical articles is slow, while asking an AI model to decide which publisher is correct would be unsafe and difficult to defend. VietFeed needs to organize source-attributed evidence around a Story and help readers catch up quickly without presenting generated content as established truth.

## 3. Solution

Upgrade VietFeed into a polyglot-persistence news application and add **StoryLens**, an event-first experience with the product promise:

> Understand a breaking Story in approximately 60 seconds without reading the same news repeatedly.

StoryLens clusters real RSS Articles into a current Story, renders source-attributed evidence immediately, then progressively adds timeline, graph, realtime, and AI-derived sections. It does not determine which source is true. Source prestige tiers provide context and can influence explainable scoring, but do not become truth labels.

The four databases answer different business questions:

- **MongoDB:** What is the current operational state?
- **Cassandra:** What happened over time?
- **Neo4j:** How are users, Stories, Articles, Sources, entities, aspects, and interests related?
- **Redis:** What is active, hot, cached, rate-limited, queued, or expiring now?

## 4. Goals

1. Run the application locally using only MongoDB, Cassandra, Neo4j, and Redis as runtime databases.
2. Preserve required existing functionality: authentication, profile, feed, search, categories, sources, articles, interactions, Reading Pass, Stripe billing, comments, moderation, administration, and source health.
3. Repair the known-broken Report/moderation flow rather than treating it as working baseline behavior.
4. Deliver StoryLens with progressive rendering, provenance, controlled-hybrid AI, human review, and deterministic fallback.
5. Demonstrate at least one visible feature and one characteristic query for each database.
6. Demonstrate controlled eventual consistency, failure visibility, retry, replay, and rebuild.
7. Keep the core demo deterministic while supporting live RSS, Google OAuth, Stripe Test Mode, and Gemini when the network is available.
8. Produce evaluation evidence using real RSS content and clearly labelled synthetic interaction/history workloads.

## 5. Non-goals

1. Production-scale multi-node clusters or high availability.
2. Claiming that a few hundred Articles require Cassandra for scale.
3. Fake-news detection, objective truth adjudication, plagiarism detection, or political-bias correction.
4. A production-grade migration of stale local SQL data.
5. Rewriting the Blade application as an SPA.
6. Fine-tuning an AI model or heavily optimizing learned scoring weights.
7. Arbitrary user-defined roles in the MVP.
8. Putting all four databases on every feature's critical path.

## 6. Domain language

- **Article:** One source-attributed RSS item or crawled article with title, excerpt/content, URL, Source, and publication time.
- **Story:** A current event or directly continuous chain of updates that may contain Articles from multiple Sources.
- **Evidence:** A source-attributed extract used by deterministic or AI-derived StoryLens output.
- **Current state:** Mutable operational data used to answer what is true inside VietFeed now.
- **Historical event:** An append-only observation or action ordered by an explicit timestamp.
- **Projection:** A derived representation written to another store for a specific query pattern.
- **Canonical ID:** A Laravel-generated UUIDv7 used across all stores and external metadata.
- **Progressive rendering:** Showing canonical evidence first and filling derived sections as they become ready.
- **Controlled eventual consistency:** One authoritative write followed by observable, retryable, rebuildable projections.
- **Source tier:** Publisher prestige/credibility context; not a truth verdict.

## 7. User stories

1. As a visitor, I want to browse recent Articles and Stories, so that I can follow current news without an account.
2. As a visitor, I want Article claims and Story evidence attributed to their Sources, so that I know where information originated.
3. As a reader, I want multiple Articles about the same event grouped into a Story, so that I do not repeatedly read duplicate coverage.
4. As a reader, I want a basic timeline and source list immediately, so that AI processing never blocks access to news.
5. As a reader, I want a validated 60-second brief, so that I can catch up quickly.
6. As a reader, I want common and unique coverage aspects, so that I can decide which original Articles to open.
7. As a reader, I want pending, partial, ready, fallback, and failed states to be explicit, so that incomplete derived content is not presented as complete.
8. As a reader, I want related Stories and Articles with an explanation path, so that recommendations are understandable.
9. As a user, I want to register, log in locally, log in with Google, verify email, reset my password, and manage my profile.
10. As a user, I want to select category interests, so that my feed can be personalized.
11. As a user, I want to bookmark and unbookmark Articles and view my current bookmark list.
12. As a user, I want to boost and unboost Articles, so that relevant news can influence realtime trends.
13. As a user, I want to comment, reply, edit, and delete my own comments under the documented authorization rules.
14. As a user, I want to report inappropriate comments and appeal eligible sanctions.
15. As a user, I want Reading Pass usage and history to be correct and recoverable.
16. As a user, I want to subscribe through Stripe Test Checkout and manage the subscription through Stripe Billing Portal.
17. As an API consumer, I want short-lived Access Tokens and rotating Refresh Tokens, so that `/api/v1` endpoints are protected independently of the Blade session.
18. As a user deleting my account, I want access revoked immediately and a 30-day recovery period before purge.
19. As an admin, I want only explicitly granted management capabilities, so that access follows least privilege.
20. As a content admin, I want to manage Categories, Sources, and Articles without receiving user-management authority.
21. As a moderation admin, I want to review reports, issue sanctions, and review appeals according to granted permissions.
22. As an operations admin, I want current Source health and historical fetch outcomes, so that RSS failures can be diagnosed and retried.
23. As a Story reviewer, I want to merge/split clusters, inspect evidence, accept/reject AI output, and retry failed processing.
24. As a Super Admin, I want to grant and revoke fixed permissions for Admins.
25. As a Super Admin, I want the final Super Admin protected from deletion or demotion.
26. As a developer, I want deterministic replay fixtures built from real RSS data, so that tests and demos do not depend on a live feed.
27. As a developer, I want failed projections visible and replayable, so that partial outages do not corrupt canonical state.
28. As a reviewer, I want a query and visible outcome for each database, so that the database choices are academically defensible.
29. As an evaluator, I want clustering and AI outputs compared against a human-labelled dataset.
30. As a demonstrator, I want the application to remain usable if Gemini, Google, Stripe, a live RSS Source, or a non-canonical projection is temporarily unavailable.

## 8. Runtime and operational constraints

1. The target machine is an Apple M1 MacBook Pro with 8 GB RAM.
2. MongoDB, Cassandra, Neo4j, and Redis run as single-node Docker containers with explicit CPU/memory limits.
3. DBeaver is the preferred development/demo GUI for browsing data and running simple supported queries.
4. DBeaver is not an application dependency. Native CLIs and automated health checks remain the reproducible verification path.
5. Docker volumes, health checks, reset, seed, replay, and teardown procedures must be documented.
6. Cassandra and Neo4j memory settings must leave the Laravel server, workers, browser, and development tools responsive.
7. Secrets and raw tokens must never be committed or printed in logs/demo scripts.

## 9. Database ownership

### 9.1 MongoDB — canonical current state

MongoDB owns current Users, roles/permissions, Categories, Sources, Articles, Stories, comments, reports, sanctions, bookmarks, boosts, subscriptions, processing state, and current validated summaries.

MongoDB requirements:

- Use unique indexes for identities and idempotency keys.
- Use Mongo-native documents/denormalized snapshots for common reads rather than recreating SQL joins.
- Keep current counters where needed instead of relying on unsupported relational aggregation patterns.
- Generate canonical UUIDv7 IDs before persistence.

### 9.2 Cassandra — append-only, query-first history

Cassandra owns time-ordered Story evolution, reading/unlock history, Source fetch history, interaction history, and moderation/authorization audit projections.

Minimum characteristic queries:

- Story events by Story and time.
- Reading/unlock history by user and month.
- Source fetch outcomes by Source and day.
- Activity by Article and day for trend reconstruction.
- Moderation events by case and time.

Tables are designed per query and partition. Cassandra is not generic CRUD storage for current Bookmark, Article, User, or Report state.

### 9.3 Neo4j — relationship topology

Neo4j owns the graph projection for relationships such as:

- User → interested in → Category.
- Article → from Source / in Category / part of Story.
- Article → mentions Entity / emphasizes Aspect.
- Story → related to Story.

Minimum characteristic queries:

- Related Story/Article through shared entities or aspects.
- Sources covering the same Story and the aspects they emphasize.
- Explainable recommendation paths from User interest to Category/Entity/Story.

Neo4j does not store full Article or Comment bodies and its internal node IDs are not business IDs.

### 9.4 Redis — ephemeral realtime and processing state

Redis owns or supports:

- Laravel web sessions.
- Cache and rate limits.
- Queue/Stream processing, retries, and dead-letter entries.
- Distributed locks for RSS fetch and projection work.
- Trending Sorted Sets.
- Reading Pass counters with TTL.
- First-party API token hashes, token-family state, and second-based TTL.

Redis must not be the only durable holder of Articles, Stories, bookmarks, subscriptions, reports, or user identity. Flush/rebuild must be possible, although active sessions may require login again.

Detailed feature mapping is maintained in `docs/architecture/database-feature-map.md` and must remain consistent with this ownership model.

## 10. Consistency and event contract

1. One operation has one authoritative store for its current state.
2. Cross-store projections are asynchronous unless the spec explicitly says otherwise.
3. Target projection convergence for Cassandra, Neo4j, and Redis is approximately five seconds in the local demo under normal conditions.
4. A failed secondary projection does not make the canonical MongoDB Article/Story unavailable.
5. Every domain/projection event contains a canonical `event_id`, event type, aggregate ID, aggregate version, and explicit occurrence time.
6. Consumers must be idempotent, detect stale/duplicate versions, retry with a bound, and send exhausted failures to a dead-letter path.
7. Admins can inspect pending/failed projection state and trigger retry/replay.
8. Projections can be rebuilt from canonical state and/or durable history.
9. Do not implement a distributed transaction across all four stores.

## 11. Core modules and interfaces

Build modules around domain behavior, not one generic repository per database.

### Content Catalog

Owns Category, Source, Article, and current Story operations, RSS deduplication, source snapshots, slugs, and canonical UUIDs.

### Story Processing Pipeline

Accepts a canonical Article/Story change, emits versioned events, tracks processing state, and coordinates retry/replay without exposing database clients to controllers.

### StoryLens

Reads canonical evidence, timeline and graph projections; exposes progressive section states; validates AI output; selects deterministic fallback; and supports human review/cluster correction.

### Interaction Module

Owns current bookmarks/boosts and emits immutable view/bookmark/boost/unlock events used by Cassandra and Redis.

### Source Health Module

Owns current Source health, fetch execution/locking, historical outcomes, and admin retry behavior.

### Authorization Module

Owns fixed roles, permission catalog, Laravel Gates/Policies, Super Admin invariants, and authorization audit emission.

### Moderation Module

Owns report, review, sanction, appeal, queue priority, and audit behavior. Its first task is to specify and repair the broken baseline flow.

### Billing Gateway

Encapsulates Stripe Checkout, Portal, signed webhook validation, idempotent webhook receipt, and MongoDB subscription projection without SQL-backed Cashier persistence.

### API Token Service

Issues opaque AT/RT pairs, stores hashes/family state in Redis, rotates Refresh Tokens, detects reuse, and revokes families on security/account events.

### Account Lifecycle Manager

Coordinates immediate revoke, Stripe cancellation, 30-day retention, recovery, idempotent purge, Neo4j cleanup, and Cassandra anonymization.

These module interfaces are the primary test seams. Controllers, commands, and workers should call domain interfaces rather than database drivers directly.

## 12. Key workflows

### 12.1 RSS ingestion and StoryLens

1. Fetch live RSS or read a frozen real-RSS fixture.
2. Validate, deduplicate, assign UUIDv7, and persist canonical Article/Story in MongoDB.
3. Publish a versioned processing event through Redis.
4. Project Story history to Cassandra.
5. Project relationships to Neo4j.
6. Update Redis processing state and trending data.
7. Render canonical Article/Source evidence immediately.
8. Poll processing status in the MVP; SSE/WebSocket is a stretch goal.
9. Generate AI output separately with timeout, structured validation, evidence IDs, and extractive fallback.

### 12.2 Story clustering and AI

1. Use explainable hybrid scoring with a small, manually sanity-checked weight set.
2. Source tier may influence confidence/context but cannot declare truth.
3. Allow human merge/split/review.
4. Store evidence and output versions.
5. Never publish unsupported claims or partial AI output as complete.

### 12.3 Stripe billing

1. Stripe Test Mode remains externally authoritative.
2. Checkout/Portal stay real and network-backed.
3. Signed webhook events update the MongoDB subscription projection.
4. Processed Stripe event IDs are unique and idempotent.
5. Duplicate and out-of-order webhooks are tested.
6. Billing uses MongoDB only among the project databases on its critical path.

### 12.4 First-party API authentication

1. Blade uses Redis-backed Laravel sessions.
2. `/api/v1` uses opaque Access/Refresh Token pairs.
3. TTL values are configured in seconds through environment variables.
4. Redis stores only hashes and token-family state.
5. Refresh succeeds only once per Refresh Token and rotates the pair.
6. Reusing a rotated Refresh Token revokes the family.
7. Logout, password change, sanction/revoke, and deletion revoke relevant families immediately.
8. If Refresh Token expires while Access Token remains valid, the Access Token continues until its own expiry but can no longer be refreshed.

### 12.5 Account deletion and sanctions

1. User deletion and administrative sanctions are separate lifecycles.
2. User deletion immediately revokes sessions/tokens, cancels active Stripe subscription, and starts a recoverable 30-day period.
3. A scheduled worker performs idempotent purge/anonymization after retention.
4. Admin sanctions retain evidence and appeal state according to a separate policy and do not automatically purge at 30 days.

## 13. Migration strategy

1. Create a read-only backup/export of the local SQL database.
2. Preserve required behavior, not stale local records.
3. Seed new configuration, Sources, Categories, permissions, and initial Super Admin.
4. Re-ingest Articles from live RSS and frozen real-RSS fixtures.
5. Introduce new stores and domain modules in testable slices.
6. Replace SQL-specific joins, raw expressions, pivots, `withCount`, transactions, and foreign-key assumptions with Mongo-native documents, counters, indexes, or application workflows.
7. Remove SQL-backed sessions, cache, queue, Cashier subscription persistence, and SQLite test dependence.
8. Final cutover succeeds only when the application boots, tests, and demos without SQLite/MySQL runtime.

## 14. Implementation order and gates

1. **Infrastructure gate:** resource-capped Docker Compose, health checks, volumes, reset/seed, and DBeaver verification.
2. **Cassandra half-day spike:** connect/write/read/reconnect using the real PHP runtime; if it fails, implement a narrow Java/.NET adapter rather than rewriting Laravel.
3. **MongoDB/Redis/Neo4j foundation:** auth/current CRUD proof, Redis session/queue/cache, Neo4j UUID upsert/query.
4. **Four-database StoryLens tracer bullet:** real-RSS replay → MongoDB canonical state → Redis processing → Cassandra timeline → Neo4j graph → progressive UI → recovery demo.
5. **Existing feature migration:** content/feed/search, interactions/Reading Pass, Source Health, Report repair/moderation, RBAC, Billing, account lifecycle.
6. **First-party API AT/RT.**
7. **AI enrichment, human review, and evaluation.**
8. **SQL removal, rebuild tests, security review, performance sanity checks, and demo rehearsal.**

Do not begin broad feature migration before the infrastructure and Cassandra gates have executable evidence.

## 15. Testing decisions

Tests assert external behavior through module, HTTP, command, worker, and database-query interfaces. They do not assert private method calls or database-driver implementation details.

Required test layers:

1. Unit tests for pure clustering/scoring, validation, state transitions, permission rules, quota logic, and token-family rules.
2. Feature tests for web/API behavior, authentication, authorization, CRUD, moderation, billing callbacks, and progressive states.
3. Container-backed integration tests for MongoDB indexes/atomic behavior, Cassandra partitions, Neo4j traversal/upsert, and Redis TTL/Streams/Sorted Sets.
4. Contract tests for any Java/.NET Cassandra adapter.
5. Idempotency/replay tests for projection events and Stripe webhooks.
6. Failure tests for unavailable consumers, retry exhaustion, dead-letter visibility, Redis rebuild, invalid/expired/reused tokens, and external AI fallback.
7. Migration/cutover test proving no runtime SQL dependency.
8. Evaluation tests/reports based on frozen RSS fixtures, deterministic synthetic events, and human-labelled gold data.

Existing Laravel feature tests are prior-art for behavior naming but must be migrated away from SQLite-specific assumptions.

## 16. Acceptance criteria

The project is complete when:

1. All four databases run through Docker on the target machine within usable resource limits.
2. DBeaver can inspect the selected simple queries where supported; native checks work independently.
3. Runtime does not require SQLite/MySQL.
4. Existing required features work against the new ownership model.
5. The Report flow has explicit acceptance tests and works end-to-end.
6. StoryLens renders canonical evidence before derived sections and never blocks on AI.
7. Cassandra, Neo4j, and Redis each power a visible, defensible capability.
8. Projection failure/recovery is demonstrated without re-ingesting canonical content.
9. Stripe Test Mode works end-to-end with MongoDB state and idempotent webhooks.
10. RBAC and Super Admin invariants are enforced server-side.
11. API AT/RT rotation, expiry, reuse detection, and revocation are tested and demonstrable with `curl`.
12. Account deletion honors immediate revoke, 30-day retention, recovery, and eventual purge/anonymization.
13. Real RSS content and synthetic interaction data are clearly distinguished.
14. Clustering and AI evaluation results are reproducible and documented.
15. The 15-, 20-, and 30-minute demo paths can run from a deterministic reset.

## 17. Supporting documents

- `docs/architecture/database-feature-map.md` — detailed feature/database reasoning.
- `docs/planning/technical-gates.md` — executable gate checklist.
- `docs/planning/project-scope-notes.md` — decision history from grilling.
- `docs/planning/future-features.md` — stretch/future backlog.
- `docs/demo/runbook.md` — demo sequence.
- `docs/demo/failure-scenarios.md` — failure contracts.
- `docs/evaluation/human-annotation-guide.md` — annotation/review protocol.
- `docs/research/` — cited exploratory research and cost estimates.

## 18. Further notes

- The known current worktree changes outside `docs/` belong to the user and are not part of this spec commit.
- GitHub tickets should slice this spec into independently reviewable tracer bullets with explicit blocking edges.
- Future items are not silently promoted into MVP. Promotion requires updating this spec or accepting an ADR.
