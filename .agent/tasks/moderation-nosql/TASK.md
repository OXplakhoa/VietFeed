# Gate 9b — Moderation on MongoDB, Redis review queue, Cassandra audit

Canonical sources, in precedence order: `docs/specs/vietfeed-nosql-storylens.md` (§9.1–9.4, §11 "Moderation Module", §12.5,
§13), `docs/planning/technical-gates.md` Gate 9 (9a/9b split), `docs/architecture/database-feature-map.md` (Report/moderation
row: MongoDB current case state, Redis review queue/counters, Cassandra audit `partition: case_id, clustering: occurred_at ASC,
event_id`; "chuỗi quyết định của một report/sanction case" is a mandatory demo query), `.agent/tasks/report-repair/` (9a contract,
GATE-REPORT-9a "Notes for 9b").

One gate part, one branch, one PR: branch `moderation/nosql-storage`, base `main` `55243cf`.

Goal: the 9a behaviour stays exactly the same (9a's acceptance tests are the regression net and must pass unchanged in intent),
while moderation state moves off SQL: reports and sanctions live in MongoDB, the admin review queue and counters live in Redis
(rebuildable), and every moderation decision is appended to a Cassandra audit timeline per case.

## Recon facts (control, 2026-11-17)

- 9a seam: `App\Moderation\ModerationService` (report, resolveCase, issueSanction, submitAppeal, decideAppeal, revokeSanction,
  lockPendingCase via SQL `lockForUpdate`) and `ModerationQueries` (inbox, caseFor, sanctions, sanctionDetail, counts). Leaks:
  SQL route-model binding (`Report $report`, `Sanction $sanction`), Eloquent relations in views, `User::sanctions()/activeSanction/
  blockingCommentSanction/isMuted/isBanned/isPermanentlyBanned/canReport` query SQL directly, API-token revocation hooks are
  `Sanction` Eloquent model events (`saving/saved/deleted` → `api_token_epoch` + `ApiTokenService::cleanupForUser`).
- MongoDB runs **standalone (no replica set)** → no multi-document transactions. Every invariant must hold through single-document
  atomic updates, conditional filters, unique indexes and idempotent retries.
- Redis runs with `maxmemory 200mb` + `allkeys-lru` → any Redis key can be evicted; the queue must be a rebuildable projection,
  never the only holder of state (spec §9.4).
- Existing patterns to reuse: Story pipeline (`App\StoryProcessing\StoryPipeline`, `App\Processing\StreamConsumer`: Mongo
  canonical with versions → Redis Stream → idempotent Cassandra projection, DLQ, replay/reemit, admin processing view);
  `CassandraClient` (keyspace guard: tests must use `vietfeed_test`); `App\Interaction\RedisResult` for checked phpredis results;
  `App\Support\MongoCounts`.
- `Comment` stays on SQL until Gate 9.5 (keep `is_hidden` / `hidden_by_sanction_id` there; cross-store references are strings/ints).

## Required outcomes

1. **MongoDB current state.** `reports` and `sanctions` become MongoDB models (UUIDv7 `_id` per spec invariant; keep human-facing
   behaviour). Introduce a per-comment case document (e.g. `moderation_cases`, unique on `comment_id`) holding the case status,
   a monotonically increasing `version`, pending/total report counts and the decision; use it as the single-document point of
   atomicity for "resolve all pending reports of a comment exactly once" (conditional update on case status + version), since
   there are no transactions. Indexes created by migration, idempotent (pattern: `2026_11_17_000001_create_mongodb_billing_indexes`).
2. **Behaviour parity.** All 9a rules 1–14 and owner decisions 1–3 hold. 9a tests may change only in setup/fixtures and storage
   assertions (e.g. `Report::count()` → Mongo); no rule may be weakened. Record every test edit with its reason.
3. **Seam completion.** Controllers bind by id through the module (no SQL route-model binding for Report/Sanction); views receive
   arrays/DTOs or Mongo models from `ModerationQueries`; `User` sanction helpers delegate to one module query with a per-request
   memo (the 9a single-read snapshot test must still pass).
4. **API-token hooks preserved.** Effective-ban creation/update, revoke and appeal approval still bump `api_token_epoch` and run
   `ApiTokenService::cleanupForUser` with the same fail-safe ordering; ApiTokensTest token/epoch assertions unchanged.
5. **Redis review queue + counters (projection).** A priority queue of pending cases (sorted set) and counters (pending cases,
   pending appeals) on the `nosql` Redis connection with namespaced keys. Priority rule (owner-confirmed 2026-11-17):
   distinct reporters desc, then severity (hate_speech, harassment, sexual_content > misinformation > spam, other), then oldest
   first. Updated on every state change; the inbox default order and admin badges read it; on missing/evicted keys or Redis
   failure the inbox falls back to a MongoDB query (correct, just slower) and an explicit `moderation:rebuild-queue` command (and
   automatic lazy rebuild) restores it. Checked results via `RedisResult`.
6. **Cassandra audit.** Table `moderation_events_by_case` (`partition: case_id`, `clustering: occurred_at ASC, event_id`) with
   event types at least: REPORTED, CASE_DISMISSED, CASE_FALSE_REPORT, SANCTION_ISSUED, COMMENT_HIDDEN, COMMENT_UNHIDDEN,
   APPEAL_SUBMITTED, APPEAL_APPROVED, APPEAL_DENIED, SANCTION_REVOKED, with actor id, case version and a short note. Written
   asynchronously via a Redis Stream + consumer exactly like the Story pipeline (outbox-style: the Mongo case/sanction document
   records the event first; projection is idempotent by `event_id`; DLQ + replay/reemit). Sanctions without a case use a
   deterministic per-user audit partition or a synthetic case id — choose and justify. Cassandra being down must not block any
   moderation action; the case page shows the audit timeline when available and an "audit pending" state otherwise.
7. **Admin views.** Case page shows the Cassandra timeline ("Lịch sử quyết định"); processing/health view (reuse the Story
   processing view pattern) shows the moderation stream lengths, pending, dead letters and a replay action.
8. **Data move.** An idempotent artisan command copies existing SQL `reports`/`sanctions` (incl. snapshots, decisions, revocations,
   appeals, `hidden_by_sanction_id` links) into MongoDB, builds case documents, emits backfill audit events and rebuilds the queue;
   safe to rerun; reports counts. SQL tables are left in place, unused (dropped in Gate 9.5); no SQL reads remain on the runtime path.
9. **Tests.** Feature tests on `composer run test` with Mongo `vietfeed_test`, Redis DB15 (live guard) and Cassandra keyspace
   `vietfeed_test` (existing guard): parity (all 9a tests), case atomicity (double resolve / resolve-vs-sanction sequential
   simulation produce one outcome), queue ordering + eviction fallback + rebuild, audit projection idempotency (duplicate
   delivery, replay), Cassandra-down path (actions succeed, audit pending, later replay fills the timeline), data-move command
   idempotency, API-token hooks. Mutating suites sequential.
10. **Docs.** Update `docs/demo/moderation-demo.md` (queue, timeline, native inspection: `mongosh`, `redis-cli ZRANGE`, `cqlsh`
    query of one case), `docs/database/nosql-local.md` (collections, keys, table), `GATE-REPORT-9b.md` in this folder.

## Test isolation (hard requirements)

Mongo `vietfeed_test`, Redis DB15 behind the live `getDbNum()` guard, Cassandra keyspace `vietfeed_test`, SQLite `:memory:`.
Never touch dev data (`vietfeed` Mongo db, Redis DB0/1/2, Cassandra keyspace `vietfeed`); no FLUSHALL, no `migrate:fresh` on dev,
no `docker compose down -v`, no container restarts. Run suites sequentially.

## Non-goals

Moving `Comment` (Gate 9.5), Neo4j abuse graph, RBAC, notifications, reopening decided cases, changing 9a product behaviour,
dropping SQL tables, Redis/Docker configuration changes.

## Stop conditions

Product-scope change beyond the control-default queue priority; need to edit owner WIP; a 9a rule that cannot hold without
transactions; failure not fixable in scope; container restart or OOM.
