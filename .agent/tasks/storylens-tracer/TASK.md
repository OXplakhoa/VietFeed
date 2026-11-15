# Gate 6 — first four-database vertical slice (StoryLens tracer)

Canonical sources, in precedence order: `docs/specs/vietfeed-nosql-storylens.md` (§9–§12.1, §15),
`docs/planning/technical-gates.md` Gate 6, `docs/architecture/database-feature-map.md` §4.3–4.6,
`docs/demo/failure-scenarios.md` Scenario 1.

Gate 6 is delivered as two PRs. This packet is **PR-A**. PR-B is listed only so its scope is not pulled in here.

## PR-A — data pipeline and recovery (branch `storylens/tracer-pipeline`, base `main` 93daf78)

Goal: a frozen real-RSS fixture becomes canonical MongoDB Article/Story state; Redis dispatches versioned
processing events; a Cassandra timeline and a Neo4j graph converge asynchronously and idempotently; the canonical
read works before any projection finishes; one failed consumer recovers through retry/replay without re-ingesting.

### Required outcomes

1. **Frozen real-RSS fixture** — a small versioned fixture (about 8–15 items, at least 2 Stories, at least one Story
   covered by 2+ Sources) captured from real public RSS feeds already listed in `database/seeders/SourceSeeder.php`.
   Keep title, link, publication time, source name/URL and a short excerpt only (trim descriptions to ~200 chars; no
   full article bodies). Record capture date and feed URLs. Story grouping for this tracer comes from a
   human-assigned `story_key` in the fixture, labelled as such — real clustering is a later gate. If real feeds
   cannot be fetched from the worker sandbox, do NOT fabricate "real" data: stop that item, label any stand-in
   as synthetic, and report it.
2. **Content Catalog ingest** — a domain entry point (not a controller talking to drivers) that reads the fixture,
   validates, deduplicates on `original_url`, assigns UUIDv7 in Laravel, and persists canonical Article and Story
   documents in MongoDB with Mongo-native shape (source snapshot embedded in the Article; Story holds article ids,
   counters and an aggregate `version`). Re-running the ingest is a no-op (no new documents, no new events).
3. **Versioned events through Redis** — every canonical change emits an event with `event_id` (UUIDv7), type,
   aggregate id, aggregate version and explicit occurrence time (spec §10.5) onto a Redis Stream on the `nosql`
   connection. Reuse the Gate 5 consumer mechanics (bounded retry ≤5, backoff, paged pending recovery, DLQ, checked
   Redis results) by extracting a shared stream-consumer core from `App\Interaction\InteractionStream` rather than
   copying it; Interaction behaviour and its tests must stay green. One consumer group per projection so a failing
   Neo4j projection does not block the Cassandra one.
4. **Redis is not the durable holder** — events must be re-derivable from MongoDB. Provide a replay path that
   re-emits projection work from canonical Mongo state (spec §10.8), so a flushed stream loses no facts.
5. **Cassandra timeline projection** — query-first table for "Story events by Story and time" (partition `story_id`,
   clustering by event time then `event_id`), idempotent on redelivery (same primary key), with the characteristic
   read exposed through the domain. Promote `mroosz/php-cassandra` from `require-dev` to `require` (no version
   change). Keyspace/table bootstrap is an explicit, documented command; nothing auto-creates schema in dev at boot.
6. **Neo4j graph projection** — idempotent `MERGE` by canonical uuid of Story, Article, Source and Category nodes and
   `PART_OF` / `FROM` / `IN` relationships; no article bodies in the graph; never use internal node ids. Source and
   Category are still SQL models without UUIDs: choose a stable interim key, record the decision and what replaces
   it when those models migrate. Expose one characteristic read (Sources covering the same Story) through the domain.
7. **Idempotent, version-aware consumers** — duplicate and stale (lower or equal aggregate version) events change
   nothing; test both projections for redelivery after "applied but not ACKed".
8. **Processing state** — per Story and per section (`timeline`, `graph`): `pending` / `ready` / `failed`, with the
   projected version, stored in MongoDB (canonical processing state per spec §9.1). A failed secondary projection
   never makes the canonical Article/Story unavailable.
9. **Canonical read before projections** — a StoryLens read model (domain interface, plus a minimal JSON endpoint if
   that is the cheapest honest HTTP proof) returns Story + source-attributed Article evidence from MongoDB
   immediately, with each derived section carrying its state. Tests: (a) no consumer has run → evidence present,
   sections `pending`; (b) Neo4j projection failing → evidence present, timeline `ready`, graph `failed`/`pending`.
10. **Recovery (failure-scenarios.md Scenario 1 at the domain/CLI level)** — with the graph consumer failing, events
    land in the DLQ after the bound and the section shows `failed`; after the fault is removed, retry/replay brings
    the section to `ready` **without re-ingesting** (Article/Story counts and ids unchanged; assert it).
11. **Thin CLI** — replay the fixture, run each consumer bounded, inspect pending/DLQ/processing state, replay
    dead letters, re-emit from Mongo, bootstrap the Cassandra schema. Readable output, tested for the inspection
    and recovery paths.
12. **Convergence** — a test shows both projections `ready` after the consumers run for the fixture. Record the
    observed local time once; make no general performance claim.
13. **Docs** — append to `docs/database/nosql-local.md`: keyspace/table, graph model, stream/group/DLQ names,
    commands, one DBeaver/native query per store for this slice, and the recovery walkthrough.

### Test isolation (hard requirements)

- MongoDB `vietfeed_test`, SQLite `:memory:`, Redis DB15 behind the existing live `getDbNum()` guard.
- Cassandra: a dedicated test keyspace (e.g. `vietfeed_test`), asserted before any write/truncate. Never write to,
  truncate or drop the dev keyspace `vietfeed`.
- Neo4j Community has one database: tests may only delete nodes they created (uuid list or a per-run marker
  property) — no `MATCH (n) DETACH DELETE n`, ever.
- New container-backed tests that need Cassandra/Neo4j go where they can run in `composer run test` only if they are
  fast and reliable; otherwise place them under `tests/Integration/` and list the exact invocation.

### Non-goals (PR-A)

Blade/UI progressive rendering and polling, admin processing page, AI brief, real clustering, entity/aspect
extraction, trending product feature, Billing, AT/RT, Source Health locks, broad SQL migration, a fifth database.

### Carried follow-ups — do not build here, keep recorded

Cassandra-backed interaction replay source for trending; idempotent move markers for the Gate 5 Lua scripts;
distributed fetch/projection lock.

### Stop conditions

Product-scope change required; need to edit owner WIP; a failure not fixable in scope; Cassandra memory pressure
(OOM/restart) — record `docker stats` before and after and stop if the container restarts.

## PR-B — progressive rendering (later, separate branch)

Blade Story page rendering canonical evidence first, polling section states, admin processing/DLQ view with retry,
and the full demo walkthrough of Scenario 1.
