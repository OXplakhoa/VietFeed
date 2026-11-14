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

## PR-B — progressive rendering (branch `storylens/progressive-rendering`, stacked on PR-A head 659d5c0)

Goal: the PR-A pipeline becomes visible through the existing Laravel/Blade app. A reader sees canonical evidence at
once and watches derived sections settle; an admin sees stuck/dead projection work and can requeue it; Scenario 1 of
`docs/demo/failure-scenarios.md` can be walked end to end. No SPA, no new database, no new dependency.

### Required outcomes

1. **Story page** — public `GET /stories/{story}` (canonical UUID; anything else, or an unknown id, is a 404) in the
   existing `AppLayout`/Bootstrap style, Vietnamese copy. The server response already contains the Story title and
   every source-attributed Article (title, link to the original, Source name, publication time, excerpt) read from
   MongoDB through `App\StoryLens\StoryLens`. It must render when Cassandra, Neo4j or both are unreachable, and for
   a legacy Gate 3 Story without tracer fields. Evidence comes first in the document; derived sections follow.
2. **Section states** — the timeline and graph sections each show exactly one of `pending` / `ready` / `failed`,
   server-rendered on first paint with the state the domain reports, never presented as complete unless `ready`.
   `ready` shows the derived data (timeline events in order; Sources covering the Story). `failed` shows a generic
   message only — no exception text, hostnames, queries or credentials.
3. **Polling (spec §12.1 step 8)** — a JSON endpoint returns section states and data for one Story; the page polls
   it and updates sections in place without a reload, including `failed` → `ready` after recovery. Polling stops
   when both sections are `ready`, pauses while the tab is hidden, backs off on errors, and keeps the last good
   render if a poll fails. Server data is inserted as text, never as HTML. Lives in `resources/js/app.js` (the
   project keeps one client file) and only runs on the Story page.
4. **Admin processing view** — `GET /admin/processing` behind the existing `auth` + `admin` middleware, linked from
   the admin nav: per section the stream/pending/DLQ counts, pending deliveries, dead-letter entries (entry id,
   Story, event type, version, attempts/last error if the consumer records them), and per Story the version and
   both section states, each linking to its Story page. Guests are redirected to login; non-admins get 403.
5. **Admin retry** — `POST` (CSRF-protected, admin only) requeues one dead-letter entry for a section through the
   domain (`StoryPipeline`/`StreamConsumer::replay`). Section and entry id are validated; an unknown section or
   entry gives a clean redirect with an error, not a 500. The controller does not run projections or touch
   database clients: a consumer (`php artisan storylens consume`) does the work, as in production shape. If
   requeueing an already-requeued entry is possible, it must not create duplicate canonical facts (rely on the
   idempotent consumers; test it).
6. **Controllers stay thin** — HTTP code calls `StoryLens` / `StoryPipeline` only. Add small domain methods where
   the admin view needs data the domain does not expose yet; do not reach into Redis/Mongo/Cassandra/Neo4j clients
   from controllers or views.
7. **Tests** — feature tests (MongoDB `vietfeed_test` + Redis DB15, no Cassandra/Neo4j required, part of
   `composer run test`): Story page with no consumer run shows evidence and both sections pending; 404 cases; legacy
   Story; JSON endpoint shape; failed section shows the generic message and no error internals; admin page
   authorisation (guest / user / admin) and DLQ listing; retry validation, CSRF and requeue effect. Container-backed
   test under `tests/Integration/`: over HTTP, both sections `ready` with data after the consumers run, and Scenario
   1 (graph failing → DLQ → page shows `failed` while timeline is `ready` → admin retry → consumer → page/JSON show
   `ready`; Article/Story ids and counts unchanged).
8. **Assets** — `npm run build` passes; no new npm or composer dependency.
9. **Docs** — a Scenario 1 walkthrough (exact commands and what to look at in the browser at each of the seven
   steps, how to make the graph consumer fail and how to restore it without destroying data) plus the new routes in
   `docs/database/nosql-local.md` or a file under `docs/demo/`; `GATE-REPORT-6B.md` in this folder.

### Non-goals (PR-B)

Home/category/search integration of Stories, AI brief, real clustering, SSE/WebSocket, a long-running worker
daemon or supervisor config, stream trimming, trending UI, Source/Category migration off SQL, restyling existing
pages.

### Test isolation and stop conditions

Same as PR-A. Additionally: never ingest the fixture into, or run consumers against, the dev stores from a test or
from the worker; the demo walkthrough is written, not executed against dev data.
