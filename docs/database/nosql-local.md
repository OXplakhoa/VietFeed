# Local NoSQL stack (Gate 1 / GitHub #1)

Single-node MongoDB + Cassandra + Neo4j + Redis via `docker-compose.yml`,
capped for M1/8GB. MySQL stays on the host (session/cache/queue are `database`
driver). All services bind **127.0.0.1** with default ports:

| Service   | Port(s)       | Ceiling (cpu/mem) | Creds (env, dev-default)              |
|-----------|---------------|-------------------|---------------------------------------|
| mongo     | 27017         | 0.75 / 768m       | `MONGO_ROOT_USER/PASSWORD`            |
| cassandra | 9042 (CQL)    | 1.0 / 1.5g, heap 768M | no CQL auth locally (loopback only) |
| neo4j     | 7687 bolt, 7474 http | 1.0 / 1g, heap 512m, pagecache 256m | `NEO4J_USER/PASSWORD` |
| redis     | 6379          | 0.5 / 256m, maxmemory 200mb | `REDIS_PASSWORD`            |

## Commands

```bash
docker compose config                                            # validate
docker compose up -d && ./scripts/nosql-health.sh --wait 120    # start + health (READY <= 120s)
docker compose ps && docker stats --no-stream                    # inspect
./scripts/smoke-mysql-path.sh                                    # app session/cache/queue smoke
docker compose down                                              # stop (keeps volumes)
docker compose down -v && docker compose up -d && ./scripts/nosql-health.sh --wait 120  # reset
```

`seed` for this slice = connectivity proof only (one native write per DB, below);
no app data is seeded. Cassandra writes belong to the T2 spike.

## Health

`./scripts/nosql-health.sh [--wait SECS]` prints `READY`/`NOT READY` per DB via
native in-container CLIs, exit 0 iff 4/4 READY. It reads creds from shell env,
then `.env`, then compose dev-defaults.

## Native CLI smoke queries (one per DB)

```bash
# mongo
docker exec vietfeed-mongo mongosh -u "$MONGO_ROOT_USER" -p "$MONGO_ROOT_PASSWORD" \
  --eval 'db.getSiblingDB("vietfeed_smoke").ping.insertOne({t: new Date()})'
# cassandra (no auth locally)
docker exec vietfeed-cassandra cqlsh -e "DESCRIBE KEYSPACES;"
# neo4j
docker exec vietfeed-neo4j cypher-shell -u "$NEO4J_USER" -p "$NEO4J_PASSWORD" \
  'CREATE (n:Smoke {t: datetime()}) RETURN n'
# redis
docker exec vietfeed-redis redis-cli -a "$REDIS_PASSWORD" SET smoke ok
```

## DBeaver notes

Create one connection per DB (all host `127.0.0.1`, localhost-only):

- **MongoDB**: host 127.0.0.1:27017, auth database `admin`, user/password =
  `MONGO_ROOT_USER`/`MONGO_ROOT_PASSWORD`. Driver: MongoDB.
- **Cassandra**: host 127.0.0.1:9042, **no auth** (leave username/password empty),
  keyspace blank. Driver: Cassandra. (Auth intentionally off locally; see below.)
- **Neo4j**: bolt `bolt://127.0.0.1:7687`, user/password = `NEO4J_USER`/`NEO4J_PASSWORD`.
  Driver: Neo4j. Browser also at `http://127.0.0.1:7474` (same creds).
- **Redis**: host 127.0.0.1:6379, password = `REDIS_PASSWORD`. Driver: Redis.

Set each password from your shell env / `.env`, never commit real values.

## Gate 3 notes (mongo-foundation)
- **Password broker stays SQL** (`password_reset_tokens` on the default SQL connection).
  Reset flow is cross-store by design: token row in SQL keyed by email, user doc in
  Mongo looked up by the same email. No custom broker; verified by PasswordResetTest.
- **Test isolation**: `RefreshDatabase` wraps only the default SQL connection, so
  `Tests\TestCase::tearDown` wipes the mongo `users` collection after each test.
  Compose Mongo must be up for the suite (`MONGO_DB=vietfeed_test` under phpunit).
- **`*_user_id` columns are strings** (bookmarks/comments/boosts/unlocks/reports/sanctions
  migrations): Mongo `_id`s don't fit BIGINT and the users FK is dropped (cascade →
  app-level, later slice). `category_user` pivot SQL kept (unwritten); favorites read
  from embedded `favorite_category_ids`. Dev MySQL needs `migrate:fresh` to pick up
  the column changes.
- **`*_article_id` columns are strings** (bookmarks/comments/boosts/unlocks migrations):
  Article `_id`s are UUIDv7 strings; articles-FKs dropped (same cascade note).
- **Boot proof (T3): slice runs on Mongo users/articles/stories; still SQL:** sessions +
  password_reset_tokens (drivers/broker untouched per non-goals), categories, sources,
  bookmarks, comments, boosts, article_unlocks, reports, sanctions, subscriptions,
  category_user, cache/jobs tables. Dashboard + article/show served green in-test.
- **`_id`/`id` trap:** the package maps storage `_id` → `id` attribute on read, so
  projections use `pluck('id')` (raw `pluck('_id')` yields nulls) while filters use
  `where('_id', …)` / `whereIn('_id', …)`.
- **`newRelatedInstance` duplication:** stock Eloquent inherits the parent connection onto
  related models, silently repointing SQL relations at Mongo — pinned on User + Article
  (2×5 lines); no shared trait until a 4th document model needs it.
- **Suite runs serially:** tearDown wipes shared compose-mongo collections without
  transactions — no `paratest` against the shared DB (use per-process `MONGO_DB` if ever parallel).

## Known deviations (approved in review)

1. **Cassandra has no CQL auth locally.** The stock `cassandra:5.0` image offers no
   env-switch for `PasswordAuthenticator`; enabling it needs a custom
   `cassandra.yaml` mount, which adds startup fragility against the READY<=120s
   criterion. Mitigation: bound to 127.0.0.1, dev-only. Revisit if threat model changes.
2. **PHP image pin**: no PHP container in this slice (app runs on host PHP 8.4.23,
   satisfies `composer.json` `^8.3`). If a PHP container is added later, pin `php:8.3-*`.
3. **Host MySQL**: `scripts/smoke-mysql-path.sh` uses the configured DB when reachable;
   with host MySQL down it verifies the same code paths on temp sqlite and labels the
   mysql leg SKIP. Re-run on a host with MySQL for the full-path PASS.
4. **ext-mongodb ^2.4 required for CI/prod** (`mongodb/laravel-mongodb ^5.7` stack).
   Exception: this host's Herd PHP pins ext-mongodb 2.3.3 — install with
   `composer require "mongodb/laravel-mongodb:^5.7" --ignore-platform-req=ext-mongodb`
   (all exercised paths runtime-validated). Do NOT pin `ext-mongodb` in composer.json
   until the dev fleet is off 2.3.3. Revisit if any path hits a 2.4-only API.

## Gate 5 / #15 — interaction Streams, synthetic trending, recovery

This section describes the original synthetic infrastructure proof. Slice 3a below covers the live producer and activity projection. The
`nosql` Laravel Redis connection uses `REDIS_NOSQL_DB` (default **2**) independently
of `REDIS_URL` (a URL `/db` can override a configured database). Sessions/default
remain DB0, cache DB1. Tests pin all three connections to DB15 and clear their URL;
every test flush asserts the live phpredis `getDbNum() === 15` immediately before
`FLUSHDB`. Never flush development DB0/1/2 or use FLUSHALL for this proof. No active
`.env` or Compose setting is changed by #15.

Logical keys (the configured `database.redis.options.prefix` is added by phpredis):

| Name | Purpose |
|---|---|
| `interactions:events:v1` | Input Stream; acknowledged entries are deleted |
| `trending-v1` | Consumer group, created at `0` with MKSTREAM |
| `interactions:dead:v1` | DLQ Stream: original stream ID, raw event JSON, attempts, last error |
| `interactions:errors:v1` | Last failure message by input ID while pending; removed at ACK/DLQ |
| `interactions:trending:demo:v1` | Synthetic Sorted Set containing scores and idempotency/version markers |
| `interactions:trending:demo:v1:rebuild:<random>` | Temporary demo projection, renamed on success |

`App\Interaction\InteractionStream` is the processing seam; `Trending` is the
projection seam. Producers supply immutable envelopes with UUIDv7 `event_id`,
`type` (`view` / `boost`), UUIDv7 `aggregate_id`, positive integer
`aggregate_version`, RFC3339 `occurred_at`, and `article_id`. For this proof each
aggregate is an independent interaction, not an Article-wide sequence. Duplicate
event IDs and versions less than or equal to the latest interaction version are ignored.
Both UUID fields are canonicalized to lowercase before dedupe/version comparisons.
In the synthetic fixture, a view adds **1** and a boost adds **5**. Ties use Redis reverse lexicographic member ordering.

A single Lua operation updates score, event marker and aggregate version. They
share one Sorted Set eviction unit: `article:<id>` has a positive score,
`event:<uuid>` has score zero, `version:<aggregate>` has the negative version.
`ZREVRANGE` returns the positive article scores first; the domain API filters
metadata. Thus eviction cannot remove a dedupe key while retaining its score.
Native phpredis 6.3.0 stream/Sorted Set commands and EVAL key arguments apply the
configured prefix; rawCommand does not. The integration test checks actual
prefixed physical keys, reads by consumer group, and compares native/Lua results.
Do not prepend the prefix again when using these native commands.

Delivery uses XREADGROUP and XPENDING/XCLAIM, including recovery by another named
consumer. Retry delays are **1, 2, 4, 8 seconds** before attempts 2–5. Failed work
stays pending until eligible. A fifth failure writes DLQ before ACK and input
deletion, with checked command results. Lua prevents interleaving, but does not
roll back earlier writes on error; cleanup key types are validated before DLQ
write and cleanup runs before ACK. RedisResult checks pending-page reads, claim,
XREADGROUP, retry-error HSET, apply, ACK, DLQ, replay and rebuild operations;
detected errors (including phpredis false replies with native error state) raise
contextual failures. Apply failures enter the bounded retry/DLQ path and can
return a successful consume exit when that failure is handled. Read, retry-error
recording, ACK/move and rebuild failures propagate to a non-zero CLI exit. Group creation, publication, terminal-error HGET, inspection and top reads also validate their Redis replies.
An empty XREADGROUP is legitimate; a false reply with native error state is not.
Retry-error HSET accepts integer 0 (updated field) or 1 (new field). If it fails,
the command stops without ACK or deletion; the delivery stays pending with its
PEL count and backoff intact. Repeated failures cannot invoke the handler beyond
five attempts; a failing terminal DLQ leaves it pending until repair, without
incrementing the terminal delivery count.
If a process stops on its fifth delivery, recovery terminalizes it
without a sixth handler invocation (after the 16-second final lease). The last
error is retained when available; an interrupted final attempt without an error
uses an explicit interruption message. A successful handler is ACKed only after
its idempotent apply; a stop between apply and ACK is safe to redeliver. Successful
ACK also removes input and retry error state. Recovery pages the entire PEL
with exclusive ID cursors (1000 rows/page by default; injectable 1..1000 for tests),
so an eligible row beyond an ineligible first page can progress. Inspection still
shows only the oldest 1000 pending and 100 DLQ rows. Recovery scan time grows with
the pending backlog; no large-load throughput claim is made. A PEL row whose
payload or event field is missing is DLQed with an empty event, its original ID,
delivery count and an explicit missing-payload reason, then ACKed. Payload check
and claim run together so XCLAIM cannot silently erase that evidence. DLQ failure
keeps the row pending. A freshly read entry without an event field follows this
same missing-payload DLQ path at attempt 1, preserving its ID without calling the
handler. Keep handlers shorter than the lease; renewable leases
and concurrent workload validation belong to Gate 6.
Use unique consumer names and schedule repeated bounded invocations; commands do
not sleep while waiting for retry eligibility.

```bash
php artisan interactions consume --consumer=demo-1 --limit=10
php artisan interactions inspect
php artisan interactions replay --id=<dead-letter-stream-id>
php artisan interactions top --synthetic --limit=10
# Pause synthetic stream writers while replacing the demo projection:
php artisan interactions rebuild --synthetic
```

Inspection prints counts, pending rows (`ID, consumer, idle-ms, deliveries`) and
DLQ fields. Replay verifies input XADD before deleting the DLQ entry, retaining
event ID and
version, with a fresh five-attempt delivery budget. It is an explicit operator
action; an unrepaired poison event will fail again. DLQ errors should contain
operational summaries, never credentials or tokens.

`InteractionReplaySource` is the durable-history seam. Its current adapter reads
**versioned synthetic** `tests/Fixtures/interactions-v1.json` from disk, not Redis.
Five known events produce `article-a = 7`, `article-b = 6`; these article names are
synthetic fixture aliases. Rebuilding validates/replays into a temporary key and
verifies staging ZADD, each event EVAL, and final RENAME. Source/staging failures
before promotion and a server-rejected RENAME preserve the old ranking, with a
non-zero CLI exit. A network interruption around RENAME leaves the outcome
uncertain: the server may have replaced the ranking even though the client did
not receive confirmation. A non-zero exit therefore does not always imply that
the old ranking survived. Failed staging cleanup is checked as well;
successful RENAME consumes staging without a redundant DEL. An
empty source replaces it with an empty ranking. Rebuild requires paused writers;
it does not coordinate concurrent live ingestion. A killed rebuild can leave a
temporary key to inspect/delete individually. The demo fixture uses synthetic article IDs and a separate sorted set. Slice 3a uses MongoDB current state for the default live rebuild.

### Eviction, flush and durability boundary

Accepted limitation: Lua prevents interleaving but is **not failure-atomic**.
In Trending::APPLY, acknowledge, deadLetter and replay, a later command can fail
after an earlier write succeeded, and Redis does not roll back those writes.
Wrong-type keys are validated or accessed before the relevant writes; the
realistic remaining trigger is a per-command Redis ACL denial (or a server fault
mid-script). Possible consequences are dedupe/version markers without the score
increment (a lost increment on retry), a duplicate DLQ row after retry, a double
replay after retry, or an ACKed-but-undeleted input entry. This local single-node
proof runs without ACLs and does not establish recovery from those failures.
Gate 6 must address idempotent move markers / deterministic destination IDs and
projection partial-write recovery. Pure reordering does not remove these cases
without introducing another loss or duplication window; no new mechanism or
script reordering is introduced in Gate 5.

Compose still uses `allkeys-lru`, maxmemory 200mb. A volume does **not** make Redis
Streams, pending entries, DLQ, retry counts, locks or dedupe state durable. Eviction
can lose any of them. Missing stream/group state is recreated; that restores
processing availability, not lost events or delivery budgets. The single ranking
key prevents partial score/dedupe eviction, but losing that key resets the entire
projection. Pause writers and rebuild from the external replay source to recover
the complete ranking. Consuming only surviving pending/new events after loss is
not a complete rebuild. Errors/DLQ are operational evidence and may also be lost;
this proof does not promise durable failure auditing or lifetime exactly-once
processing. Five-attempt bounds apply while the Stream/PEL survives, and reset on
explicit replay or Redis loss.

The current deterministic source can reconstruct **only its synthetic dataset**.
Durable real interaction capture, a Cassandra replay adapter, reconciliation after
loss, bounded metadata retention, durable failure visibility, and retry recovery
under larger/concurrent workloads remain Gate 6 work. No infrastructure redesign
or eviction-policy change is proposed in this ticket. Mongo canonical entities
and the remaining SQL business fixtures are independent of Redis. The guarded
flush test seeds Mongo users/articles/stories and SQLite bookmarks/subscriptions,
logs in over HTTP, fills Redis session/cache/stream/trending, flushes **DB15 only**,
proves identical durable counts, guest redirect then successful re-login, and
reconstructs the same top-N from disk.

Fetch/projection distributed locks (TASK deliverable 5) are deferred: Source
Health fetch ownership and Gate 6 live projection coordination need an agreed
lease/renewal/failure boundary, and `allkeys-lru` can evict a lock. Adding an unused
lock here would not prove either workflow. The present consumer lease/recovery
proof is not a fetch lock.

All tests extending `Tests\TestCase`, including `tests/Integration/*`, require
**no `bootstrap/cache/config.php`**, Mongo database **`vietfeed_test`**, default SQL
connection **`sqlite`** with database **`:memory:`**. `phpunit.xml` supplies those
values; conflicting exported environment variables must be unset or set to the
same test values. The guard runs before migrations and retains its refusal on
misconfiguration. Teardown verifies the live Mongo database and deletes test
`users`, `articles`, and `stories`; even Neo4j/Cassandra integration tests therefore
need Mongo available. Redis test configs pin default/cache/nosql to DB15 with
URL overrides removed; every flush additionally verifies live `getDbNum() === 15`.
Never run these tests against a shared DB15 or use FLUSHALL.

Run the proof serially with all four Compose stores healthy. If config is cached,
run `php artisan config:clear` before PHPUnit (this changes only generated local
config cache; it does not weaken the guard). Integration suites are outside the
default Unit/Feature suites and must be invoked explicitly:

```bash
vendor/bin/phpunit tests/Integration/MongoConnectionTest.php tests/Integration/MongoAuthDoubleTest.php
vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php
vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php
```

Neo4j uses UUID-scoped fixtures and removes them; the existing Cassandra spike
writes random partitions in `vietfeed_spike` and leaves them for inspection.
Neither integration invocation is a Redis flush or a development-data migration.

Run the Streams proof:

```bash
vendor/bin/phpunit tests/Feature/RedisStreamsTest.php
GATE15_DLQ_PROOF=1 vendor/bin/phpunit tests/Feature/RedisStreamsTest.php --filter=test_dlq_inspection_command_proof_and_replay
```

The second command invokes `interactions inspect` on the guarded live DB15,
prints the terminal DLQ, then tests replay and cleans DB15. The suite asserts
Mongo `vietfeed_test` and SQLite `:memory:` before RefreshDatabase migrations.
This is isolated test evidence, not a development database flush procedure.

## Gate 6 PR-A / #19 — canonical RSS → Story projections

`App\Content\ContentCatalog::ingest(array $fixture)` is the Content Catalog entry
point. `StoryPipeline` owns dispatch, projection versions, Mongo processing state,
inspection and recovery; `StoryLens::read($storyUuid)` returns canonical evidence
before any worker runs. `php artisan storylens read --story=<uuid>` exposes that
read as JSON. PR-A adds no Blade/polling/admin UI; that is PR-B.

### Frozen fixture and canonical identities

`tests/Fixtures/storylens-rss-v1.json` contains ten captured public RSS items from
VnExpress and Tuổi Trẻ world feeds, with capture time and feed URLs. It contains
two **human-assigned** Story groups (US flight disruption and the Cambodia visit
around the Francophonie summit), five Articles and two Sources in each. Publisher
claims are evidence, not independently verified facts. Excerpts are at most 200
characters, with no full bodies. Tuổi Trẻ's timezone-free RSS dates were interpreted
as publisher local time (+07:00); this normalization is recorded in the fixture.

Articles and Stories use Laravel-generated UUIDv7 strings. Articles embed
`source_snapshot` and `category_snapshot`; Stories embed `article_ids`,
`article_count`, `source_count`, aggregate `version`, immutable `events` and
`processing.{timeline,graph}`. Each section has `state` and `projected_version`.
The ingest boundary ensures unique `articles.original_url` and a unique partial
`stories.story_key` index. A repeated ingest emits no new events and creates no
new documents. An existing RSS Article can be attached without changing its ID.

Source/Category now have canonical Mongo UUIDv7 `_id` values (Gate 9.5).
Their **content identities for the graph** remain deterministic UUIDv5 values in
the URL namespace over `vietfeed:source:<exact feed_url>` and
`vietfeed:category:<slug>`, embedded in snapshots as `uuid`. These identities are
independent of canonical Mongo IDs and display names. Configuration import never
rewrites snapshots or graph identities (binding REVIEW-1 control decision).

### Events, groups and state

An immutable `story.article_added.v1` envelope contains UUIDv7 `event_id`,
`aggregate_id` (Story UUIDv7), positive `aggregate_version`, explicit UTC
millisecond `occurred_at`, and `article_id`. The event list and aggregate advance
in the same Mongo Story write. A consumer validates the envelope against this
canonical history before projecting. A newer version projects the entire durable
prefix, so out-of-order delivery cannot silently omit earlier history. Lower/equal
projected versions do nothing. A successful write updates only that section's
Mongo state through a conditional field update. The success update compares against
Story.version in the current Mongo document atomically: `ready` means that the
recorded projected_version equalled that version at that write. An attachment
atomically advances version/history and marks both sections pending, preserving
consumer projected_version and retaining error together with failed_version
through intervening ingests. Late successes cannot lower progress; late failures
cannot overwrite an equal/newer success. Failures record `failed_version` alongside
`error`; a lower-version failure cannot replace that marker. A success advances
projected_version but retains the failed state, error and failed_version while
that failure is newer. Only equal-or-newer success clears both marker fields and
chooses ready/pending against the current Mongo version. The paused operator
`reemit` reset explicitly clears both fields before dispatch. Read/inspect also downgrade a stale ready
marker to pending when its version differs from their Mongo snapshot. Legacy Gate 3
Stories with no processing/history/evidence list are readable with empty evidence,
version 0 and pending sections; unknown events are rejected against empty history.

This is not a cross-store read transaction: a writer may advance Mongo after the
read snapshot, and derived data may change between reading state and reading a
section. A projection may also finish before its Mongo success marker is written;
the section stays pending/failed until retry records success. Independent same-section
workers and concurrent rebuild operations remain outside this tracer's operational
contract. Normal consumer/ingest progress is monotonic; an explicit paused `reemit`
resets both sections to version 0 for rebuilding lost derived data.

Logical Redis names below receive the configured phpredis key prefix:

| Name | Purpose |
|---|---|
| `stories:events:v1` | Shared dispatch Stream |
| `story-timeline-v1` | Timeline group, starts at 0 |
| `story-graph-v1` | Graph group, starts at 0 |
| `stories:dead:timeline:v1`, `stories:dead:graph:v1` | Per-section DLQ Streams |
| `stories:errors:timeline:v1`, `stories:errors:graph:v1` | Pending failure messages |

`App\Processing\StreamConsumer` is extracted from Gate 5, with Interaction's
existing facade retained. Story groups ACK independently and **retain stream
entries** so one group cannot delete another group's unread work. Therefore
`lengths.stream` is retained input size, not backlog. Entries remain after ACK by
**both** groups and are never trimmed; replay/reemit append more entries. Follow-up:
implement safe trimming only once both groups have passed an entry (including
pending deliveries), with Mongo re-emission as the durable recovery path. Interaction retains its
original delete-after-ACK behavior. Recovery pages the pending list; retry is
bounded to five handler attempts with exponential backoff (default base 1000 ms,
then 2000/4000/8000 ms; `STORY_RETRY_MS` overrides the base). Process interruption
on the final lease is terminalized without a sixth handler attempt. Consumers do
not sleep or block waiting for backoff: invoke the bounded command again later.
Inspection returns the oldest 1000 pending entries / 100 dead letters.

Shared core operations check native phpredis result/error state, including
publish, group creation, receive, claim, retry recording, ACK, DLQ, replay and
inspection. The existing Gate 5 `RedisResult` helper is reused. A handled projection
failure returns CLI exit 0 once recorded for retry/DLQ; stream/recording failures
propagate to CLI exit 1. Inspect state/DLQ to distinguish successful projection
from successfully recording a failed delivery.

### Cassandra timeline and Neo4j topology

Schema bootstrap is **explicit**; app boot and consumers do not create it:

```bash
php artisan storylens schema
```

The configured `CASSANDRA_KEYSPACE` defaults to `vietfeed`; tests use
`vietfeed_test`. The single-node keyspace uses SimpleStrategy, replication 1.
`story_events_by_story` has columns `story_id uuid`, `occurred_at timestamp`,
`event_id uuid`, `aggregate_version bigint`, `type text`, `article_id uuid`.
Primary key is `((story_id), occurred_at, event_id)`; clustering order is
`occurred_at DESC, event_id DESC`. Explicit occurrence time is independent of
UUIDv7 generation time. Redelivery uses the same primary key. CQL responses are
typed results (DDL may be schema-change or void when already present); invalid
queries throw, unlike phpredis's false results. `CassandraClient` is the single
construction point; `StoryTimeline::events` is the partition read interface.
The driver is now a runtime dependency at its unchanged `^1.2` constraint.

Neo4j uses `MERGE` on `uuid` for Story, Article, Source and Category, plus
`(Article)-[:PART_OF]->(Story)`, `(Article)-[:FROM]->(Source)` and
`(Article)-[:IN]->(Category)`. A projection runs in one Cypher statement/transaction;
Story's graph version prevents a lower version replacing a newer snapshot. No
article bodies, SQL joins or internal node IDs are used. `StoryGraph::sources`
returns Sources covering the same Story and their Article counts. `Neo4jClient`
is the single construction point, returns a CypherList and surfaces exceptions.

### Thin CLI and normal local use

```bash
php artisan storylens ingest
php artisan storylens consume --section=timeline --consumer=timeline-local --limit=100
php artisan storylens consume --section=graph --consumer=graph-local --limit=100
php artisan storylens inspect --section=graph
php artisan storylens read --story=<story-uuid>
php artisan storylens replay --section=graph --id=<dead-letter-stream-id>
php artisan storylens reemit --story=<story-uuid>
# Omit --story to re-emit all tracer Stories; use --fixture=/path/to/frozen.json for ingest.
```

`reemit` requires paused writers/consumers and a reachable, writable Neo4j store.
Before changing any Mongo processing state or publishing any events, it calls
`StoryGraph::resetForRebuild` to reset the projection-side Story version to 0 for
all affected Stories in one graph statement. A graph reset failure aborts with
`Cannot rebuild: graph reset failed`; Mongo state and dispatch remain unchanged.
Use ordinary retry/DLQ replay while a projection store is down; restore Neo4j
before attempting this explicit rebuild. This precondition applies even when
repairing only Cassandra or Redis dispatch.

After the graph reset, reemit sets both Mongo sections pending with
projected_version=0 and clears error/failed_version, then publishes the original
stored event IDs, times and versions from Mongo. Every consumed prefix is MERGEd
again, restoring missing PART_OF/FROM/IN relationships and missing Article,
Source or Category projection nodes even when the Story survived at v5. Ordinary
stale/duplicate redelivery still trusts an equal-or-newer graph marker and reports
the prefix covered without rewriting it; only explicit rebuild resets that marker.
Mongo advances through successfully consumed event versions and reaches ready
only at the current version. Cassandra rewrites every original primary key,
repairing individual lost rows over surviving rows without duplicates. Healthy
rebuild converges with empty pending/DLQ and the full canonical fact set.

Rebuild is additive: it does **not remove extra stale facts**, unwanted nodes,
relationships or Cassandra rows, resolve duplicate graph identities introduced
externally, or repair missing/corrupt canonical Mongo evidence/history. It does
not re-ingest or create replacement canonical Articles/Stories or timestamps.
The reset and later Mongo/Redis writes are not one cross-store transaction;
a subsequent Mongo CAS or publish failure requires another paused reemit after
fixing the cause. Repeating re-emission is safe for canonical projection facts.
DLQ replay republishes into the shared Stream; the other group's duplicate is
ignored. If Redis publish fails after Mongo commit, content remains readable with
pending sections: restore Redis and Neo4j, pause writers/consumers and use reemit.
Re-running ingest after this boundary is a no-op and does not repair lost dispatch.

The earlier Article-save → Story-save interruption is repaired differently:
`php artisan storylens ingest --fixture=/path/to/the-same-frozen.json` reuses the
persisted Article UUID and its assigned Story UUID, then attaches it with exactly
one durable event. `reemit` cannot recover an attachment absent from Story history.
For the Story-save → Redis-publish interruption, pause writers/consumers and run
`php artisan storylens reemit --story=<story-uuid>`, then resume both
`php artisan storylens consume --section=timeline --limit=100` and
`php artisan storylens consume --section=graph --limit=100`. Verify with
`php artisan storylens read --story=<story-uuid>` and inspect pending/DLQ. After an
external projection failure reaches DLQ, fix the cause and use
`php artisan storylens replay --section=<timeline-or-graph> --id=<dlq-id>`, then consume.
Graph validates all distinct canonical Article IDs before writing and requires the
Cypher projected count to equal that prefix size when writing. The statement
compares against its expected_count parameter and advances the Story version/title
only on equality, inside the same transaction. A mismatch may leave idempotently
MERGEd facts but cannot advance the marker; retry must project again rather than
claim coverage. PHP checks both count and returned version before recording Mongo
success. An ordinary covered result must hold at least the requested event version. Timeline validates the same
canonical prefix and requires each CQL INSERT to return a successful void result;
a later INSERT failure leaves partial idempotent rows but never marks success.
Missing canonical Articles require restoring canonical evidence before retry/replay;
reemit alone cannot invent missing evidence.

### One characteristic native / DBeaver query per store

Replace the UUID and logical key prefix with the current configured values;
connect with the existing local credentials without printing them. Use
`vietfeed_test` / Redis DB15 only for isolated tests; normal dev queries use their
configured database/keyspace/Redis DB.

```javascript
// Mongo shell / DBeaver Mongo: immediate canonical evidence and processing state
db.getSiblingDB('vietfeed').stories.find({_id: '<story-uuid>'},
  {article_ids: 1, article_count: 1, source_count: 1, version: 1, processing: 1})
db.getSiblingDB('vietfeed').articles.find({story_id: '<story-uuid>'},
  {title: 1, original_url: 1, source_snapshot: 1, description: 1})
```

```sql
-- CQL / DBeaver Cassandra: one partition, newest event first
SELECT occurred_at, event_id, aggregate_version, type, article_id
FROM vietfeed.story_events_by_story WHERE story_id = <story-uuid>;
```

```cypher
// Neo4j Browser / cypher-shell / DBeaver Neo4j
MATCH (:Story {uuid: '<story-uuid>'})<-[:PART_OF]-(a:Article)-[:FROM]->(src:Source)
RETURN src.uuid, src.name, count(DISTINCT a) AS articles;
```

```text
# Redis native interactive CLI, connected to the configured NoSQL DB:
XINFO GROUPS <configured-prefix>stories:events:v1
XPENDING <configured-prefix>stories:events:v1 story-graph-v1
XRANGE <configured-prefix>stories:dead:graph:v1 - + COUNT 10
```

### Recovery walkthrough (Scenario 1, domain/CLI level)

1. Bootstrap Cassandra explicitly, ingest the fixture once, save the Article/Story
   IDs and counts, and read a Story: canonical evidence exists; both sections pending.
2. Consume timeline. For a reversible connection fault, invoke only the graph
   consumer with `NEO4J_BOLT_PORT=1 php artisan storylens consume --section=graph`.
   Repeat after each backoff until the five-attempt bound. For the ten-item fixture
   each event has its own budget; inspect until the relevant work is terminal.
3. `storylens inspect --section=graph` shows failed section state and DLQ envelopes
   with attempts=5. `storylens read --story=<uuid>` still returns canonical evidence
   and a ready timeline. These commands use the normal working graph port.
4. Remove the process-local port override. Replay each relevant DLQ ID using
   `storylens replay --section=graph --id=<id>`, then consume graph again. It becomes
   ready at the current version; canonical IDs and counts are unchanged. A later
   timeline consume ignores the duplicate replay. There is no re-ingest step.
5. For lost Redis or projection data, pause writers/consumers and use `reemit`,
   then consume both groups. Never flush a dev Redis DB as part of this proof.

The implementation also ran this walkthrough with separate real `php artisan`
processes on isolated test stores and a one-item fixture subset to keep memory tiny.
Only that run's Neo4j UUIDs and Cassandra partition were cleaned afterward.
For a reproducible isolated CLI run, supply all of these process-local overrides:

```bash
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=''
export MONGO_DB=vietfeed_test CASSANDRA_KEYSPACE=vietfeed_test
export REDIS_DB=15 REDIS_CACHE_DB=15 REDIS_NOSQL_DB=15 REDIS_URL=''
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export PULSE_ENABLED=false TELESCOPE_ENABLED=false NIGHTWATCH_ENABLED=false
# Optional test-only acceleration (production/default base remains 1000 ms):
export STORY_RETRY_MS=1
```

The command refuses a testing invocation unless there is no config cache, Mongo
is `vietfeed_test`, SQLite is `:memory:`, Cassandra is `vietfeed_test`, and the live
NoSQL Redis client reports DB15. New Cassandra operations independently recheck
the test keyspace before access. Do not use these exports in a shell running the
development app; a separate shell/process avoids changing its configuration.

### Tests and boundaries

```bash
vendor/bin/phpunit tests/Feature/StoryCatalogTest.php
vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php
```

Feature coverage uses guarded Mongo/Redis and remains in `composer run test`.
Cassandra/Neo4j projection/recovery coverage lives in Integration and is invoked
explicitly. No new Mongo collections require teardown changes. Neo4j tests use
unique per-run Source feed identities/Category slugs and clean only the tracked
Story/Article/Source/Category UUIDs. Cassandra tests delete only their tiny
`vietfeed_test` partitions, with a keyspace assertion before cleanup. The
Cassandra spike now also uses guarded `vietfeed_test` and deletes only its created
partitions in `story_timeline`; it no longer writes to `vietfeed_spike`.

Limitations: this is a single-writer tracer, with no distributed fetch/projection
lock, automatic outbox relay, stream retention policy or large-history design.
Mongo Article and Story writes are separate (no cross-document/distributed
transaction); rerunning fixture ingest can attach an already-written Article and
reconstruct an interrupted initial Story using its assigned ID. Once the Story
commit exists, its embedded history is sufficient for explicit dispatch/projection
rebuild. Ingestion does not silently retry a rejected publish on duplicate ingest.
Re-emission and schema bootstrap are operator actions, not boot side effects.
A transient derived-read exception returns a failed section alongside canonical
evidence; it does not overwrite the Mongo worker's recorded processing version.

The inherited Lua moves are non-interleaved, not failure-atomic after every write;
late server/ACL/transport faults may leave duplicate DLQ/replay work. Projection
idempotence tolerates duplicate facts; move-marker redesign is deferred. Stream
retention and embedded Story history are intentionally suitable only for this tiny
tracer; no general scale/performance guarantee is claimed. Cassandra interaction
replay for trending, Gate 5 move markers, distributed locking, progressive UI,
admin controls, AI and real clustering remain deferred.

### Gate 6 PR-B: progressive HTTP rendering

Public Story and JSON section routes, admin inspection/retry, and the complete
seven-step browser failure/recovery walkthrough are documented in
[StoryLens progressive rendering](../demo/storylens-progressive-rendering.md).
Run `vendor/bin/phpunit tests/Feature/StoryPageTest.php` for Mongo/Redis HTTP behavior
(included in `composer run test`) and
`vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` for tiny,
isolated real-projection HTTP convergence, read outages and graph DLQ recovery.

## Gate 8 — first-party API token state

`App\ApiTokens\ApiTokenService` alone owns token keys. It uses the existing `nosql`
connection (default NoSQL DB2; **DB15 only for tests**, protected by live getDbNum).
The web session stays independent. Redis loss invalidates API credentials and
requires login again; Mongo User identity is loaded on every bearer request and
refresh. Roles/permissions are never copied into Redis. Existing SQL-backed
sanctions and boosts are reused; migrating those models belongs to later gates.

All logical keys below receive `database.redis.options.prefix` exactly once:

| Key | Type/value | Physical TTL |
|---|---|---|
| `api-token:access:<SHA-256>` | JSON `{uid,family,expires}` | configured access seconds + 60 |
| `api-token:refresh:<SHA-256>` | JSON `{uid,family,expires,used?}` | configured refresh seconds + 60 |
| `api-token:family:<random-id>` | JSON `{uid,epoch}` | longest issued token's expiry + 60, extended on rotation |
| `api-token:user:<user-id>` | sorted set: family ID, score = family physical expiry | expires at greatest score |

Access/refresh values are independently random 32-byte (256-bit), unpadded
base64url strings. SHA-256 lookup is adequate for uniformly random secrets; no
password hash or raw token enters this keyspace. Never inspect a token by pasting
its raw value into a command, URL or file. The family ID is independently random
and is not itself a usable credential.

`AUTH_ACCESS_TOKEN_TTL_SECONDS` defaults to 900;
`AUTH_REFRESH_TOKEN_TTL_SECONDS` defaults to 2592000. Positive integer values in
1..31536000 are accepted through `config/nosql.php`; invalid values fail closed
with 500 `server_error`. Neither `.env` nor the Docker configuration was changed for this gate.

Lua compares `expires` with Redis TIME in integer seconds on every lookup. The
extra **60-second diagnostic grace** preserves the expired-vs-unknown distinction
without extending authorization. After that horizon metadata disappears and the
same credential returns invalid. Revoked families stop all access/refresh tokens
immediately, regardless of TTL. Refresh expiry does not revoke valid access.

Rotation is one Lua operation: check old state again, mark old refresh used with
KEEPTTL, create replacement hashes and extend family/index TTL as needed. Older
access tokens keep their independent lifetime. Replaying a rotated refresh during
its original lifetime deletes family authority, invalidating every generation.
A duplicate concurrent refresh therefore also invalidates the winner's pair:
clients must serialize refresh and reauthenticate after ambiguous retries.

Issue/rotation prune family-index members whose physical horizon has passed.
Account revocation increments Mongo User `api_token_epoch` (missing means integer
0). Issue stores the epoch from the same User snapshot as credential verification
in the family. Every bearer/refresh checks that epoch against the current Mongo
User and rejects a mismatch as `401 token_family_revoked`. Password update/reset
uses a single Mongo write with password `$set` and epoch `$inc`, including when
the model snapshot is stale. An active temporary/permanent ban increments the
User epoch after the SQL sanction save succeeds. All sanction permission helpers
query any relevant active sanction; a newer warning cannot hide an active ban.

Redis cleanup is best-effort after persistence: it iterates only that user's sorted
family index and deletes Redis families atomically, without throwing store failures
into web password, deletion or ban actions. Deletion is authoritative through a
missing Mongo User. Warnings contain operation and exception class only. A login
whose old credential snapshot precedes the security write can still return a pair,
but that pair fails on first use. An already-authorized in-flight request is not
cancelled. SQL sanction save and Mongo epoch increment are separate writes; if the
Mongo increment fails, the active ban still denies requests, but lifting it before
retrying the increment could restore old families. Bulk writes bypassing model
events must use equivalent epoch updates. No cross-store transaction is claimed.
Request paths never use KEYS/SCAN. Logout removes
one family/index member. Token metadata expires naturally, so revocation need not
find every generation. All Redis operations go through RedisResult; Lua uses
redis.call and preflights the relevant key types before state mutation. Driver
false results and script errors become a generic 503 without driver diagnostics.
Redis scripts do not roll back earlier writes on server faults/ACL failures; the
local deployment is a standalone Redis with the existing ACL/configuration.

Native, **read-only** inspection (interactive redis-cli; supply its password through
an interactive secret prompt or runtime REDISCLI_AUTH, never a literal argument).
Connect to the explicitly chosen isolated DB; do not flush a development DB:

```bash
# Host redis-cli, with REDISCLI_AUTH supplied securely in the current environment:
redis-cli -h 127.0.0.1 -p 6379 -n 15
```

Inside redis-cli, substitute the configured physical prefix and IDs/hashes learned
from the diagnostic scan, never raw AT/RT. SCAN is **operator inspection only**:

```text
SCAN 0 MATCH <configured-prefix>api-token:* COUNT 100
TYPE <configured-prefix>api-token:user:<fixture-user-id>
ZRANGE <configured-prefix>api-token:user:<fixture-user-id> 0 -1 WITHSCORES
TTL <configured-prefix>api-token:user:<fixture-user-id>
GET <configured-prefix>api-token:family:<family-id>
TTL <configured-prefix>api-token:family:<family-id>
GET <configured-prefix>api-token:access:<sha256-from-scan>
TTL <configured-prefix>api-token:access:<sha256-from-scan>
GET <configured-prefix>api-token:refresh:<sha256-from-scan>
TTL <configured-prefix>api-token:refresh:<sha256-from-scan>
```

Repeat SCAN with its returned cursor until zero for a complete inspection.
TTL -2 means metadata gone, not a reliable expired-vs-revoked diagnosis. No raw
credentials should appear in these values. Tests scan **all DB15 keys and dumped
values**, and capture the flow's logger output, to assert raw-token absence.

See [the curl walkthrough](../demo/api-token-demo.md). Its commands were not
executed; each HTTP step is mapped there to an automated passing test.

### Gate 8 API errors and login limits (review-fix round 1)

Errors remain `{ "error": { "code": "..." } }`, with no validation fields/values.
Symfony HTTP exceptions preserve their status (401 `unauthenticated`, 403
`forbidden`, 404 `not_found`, 405 `method_not_allowed`, 429 `rate_limited`).
Authorization failures are 403; missing models 404; validation 422
`validation_error`; unexpected errors/configuration failures 500 `server_error`.
503 `service_unavailable` is reserved for token-store access failures. Unexpected
API failures pass through Laravel's reporter using a sanitized exception message
(class only) and empty context, removing input, headers, original exception
messages/context and traces. Non-API rendering/reporting and console reporting
retain the normal framework behavior.

Login validates before throttling. Only rejected credential checks consume the
shared Redis cache counters: 5/minute per email hash + IP, 30/minute per IP.
Successful login clears the account counter and consumes neither counter.
A noisy account leaves other accounts at the same IP usable until the IP ceiling;
429 replies include `Retry-After`. Keys hash email/IP and never contain passwords.
The API explicitly uses the existing Redis cache store even if the default cache
store is process-local. Test workers pin and guard cache/nosql Redis at DB15.

## Gate 7 — Mongo billing

Billing now uses `App\Billing\BillingGateway` and the directly required
`stripe/stripe-php` v17.6.0. Cashier, its configuration, five SQL billing migrations
and unused Money/ICU polyfill dependencies are removed. The historical Gate 3 list
above described the earlier state: subscriptions are now Mongo documents, not SQL.
Remaining non-billing SQL stores and Redis web sessions are unchanged.

| Collection | Fields and durable indexes |
|---|---|
| `users` | `stripe_customer_id`, partial unique index (`$type: string`; null/empty values are unset first); legacy `stripe_id` retained by idempotent backfill |
| `subscriptions` | `_id` = Stripe subscription ID; unique `stripe_subscription_id`; indexed `user_id` UUID; type, customer ID, status, price, period end, cancel/ended fields, `projection_version`, `applied_event_ids` (last 50), transient `lock_token`/`lock_until` |
| `processed_stripe_events` | `_id` = unique Stripe event ID; type index; received/processed BSON UTC timestamps; `result` (`projected`, `ignored`, `unknown_user`); transient `claim` token and `lease_until` while unfinished |

Migration: `2026_11_17_000001_create_mongodb_billing_indexes.php`. It is safe to
rerun; it never replaces an existing new customer ID or deletes the legacy ID.
Mongo's unique index rejects conflicting customer/subscription/event identities.
Apply just this migration using the Mongo connection before real acceptance;
see [the billing walkthrough](../demo/billing-demo.md) for the exact command and
read-only native queries. No dev migration was executed by the worker.

`User::isPro()` delegates to the gateway: true iff any Mongo subscription is
`type=pro`, status `active` or `trialing` and `price_id` equal to `STRIPE_PRO_PRICE_ID` (when that is empty, type and status only). Period-end cancellation keeps Pro while
status is active. Admin page users receive one batched entitlement read; no per-row
billing queries. Stripe outages do not change the stored entitlement.

The signed webhook is stateless and CSRF exempt, with no web session, sanction or
SQL middleware. Stripe's SDK verifies the raw body/signature with its default
300-second tolerance. Only verified events enter Mongo. Receipt claims use a
60-second lease; a busy duplicate returns retryable 503, a processed duplicate 200.
The payload is only a trigger: every handled event retrieves the current subscription
from Stripe under a fenced per-subscription lock (`lock_token`/`lock_until` on the
subscription document, expiry capped at the receipt lease) and writes that state, so
no ordering is derived from events. A contended lock is polled for 5 seconds, then
503. The write is skipped if the event ID is already in `applied_event_ids` (last 50),
which prevents reapplication when a worker crashes after projection but before
receipt acknowledgement. Failed events stay unfinished: live endpoints are retried
by Stripe; under `stripe listen` re-send with `stripe events resend <evt_id>`. No
replica-set transaction or Redis lock is required. Events carry no payloads or
signatures; unknown-user and irrelevant events are acknowledged and recorded.

Tests run only on `vietfeed_test`, SQLite `:memory:` and guarded Redis DB15.
`StripeBillingTest` uses signed fixtures, two independent HTTP workers for duplicate
races and a fake Stripe interface; it never calls Stripe. `ProSubscriptionTest`
checks Checkout metadata/reuse, Portal/errors, Reading Pass and admin behavior.
The real Test Mode walkthrough is deliberately reserved for control/reviewer.

## Gate 9b — moderation on MongoDB, Redis review queue, Cassandra audit

Reports, sanctions and per-comment case documents are canonical on MongoDB; the SQL
`reports`/`sanctions` tables stay in place but unused (dropped in Gate 9.5) and are
read only by `php artisan moderation:import-sql`. Comments moved to MongoDB in Gate 9.5
slice 2 (see below); `comment_id` references are now the comment's string id.

| Store | Name | Role |
|---|---|---|
| Mongo | `reports` | one per reporter+comment (unique `reporter_id,comment_id`), UUIDv7 `_id`, comment snapshot, decision, `case_id` |
| Mongo | `sanctions` | UUIDv7 `_id`, 9a fields + `version`, `events[]` (audit outbox), `audit_case_id`, `unhide_pending` |
| Mongo | `moderation_cases` | one per comment (unique `comment_id`): `status`, `round`, `version`, `pending_count`, `severity`, `round_started_at`, `decisions[]`, `events[]` |
| Redis | `moderation:queue:v1` (ZSET) + `:meta` (HASH `count`,`built_at`) | priority queue of pending cases (projection) |
| Redis | `moderation:counters:v1` (HASH, TTL 300 s) | `pending_appeals`, recomputed from Mongo |
| Redis | `moderation:events:v1`, group `moderation-audit-v1`, `moderation:dead:audit:v1`, `moderation:errors:audit:v1` | audit stream / DLQ (shared `StreamConsumer`) |
| Cassandra | `moderation_events_by_case` | `PRIMARY KEY ((case_id), occurred_at, event_id)`, `CLUSTERING ORDER BY (occurred_at ASC, event_id ASC)` |

**Atomicity without transactions.** Mongo is standalone, so each moderation rule is a
single conditional document update: deciding a case requires `status = pending`, the
read `version` and no in-flight claim; a sanction from a case first *claims* the case,
inserts the sanction, then *confirms* (or *releases* on insert failure). Report
documents, comment hide/unhide, audit publish and queue sync are idempotent follow-ups
re-derived from the case/sanction document. `php artisan moderation:repair` finishes
anything a crash interrupted (stale claims after a 10-minute lease are confirmed if
the sanction exists, released otherwise). Details and the crash table:
`.agent/tasks/moderation-nosql/GATE-REPORT-9b.md`.

**Queue.** Score = `(pending reporters·4 + severity)·2^32 + (2^32−1 − round start
seconds)`, read with `ZREVRANGE`: more distinct reporters first, then severity
(hate_speech/harassment/sexual_content > misinformation > spam/other), then oldest.
Under `allkeys-lru` either key may vanish; reads trust Redis only when `meta.count ==
ZCARD`, otherwise they answer from MongoDB (same order, index
`status,pending_count,severity,round_started_at`) and rebuild lazily. Explicit rebuild:
`php artisan moderation:rebuild-queue`. Admin UI: `/admin/reports?view=queue`.

**Audit.** Each state change appends its event(s) to the Mongo document in the same
update, then publishes to the stream (best effort). Projection checks the event against
the canonical Mongo copy and `INSERT`s by primary key, so duplicates/replays are
harmless. Cassandra down never blocks an action: deliveries retry, land in the DLQ
after 5 attempts, and are replayed from `/admin/processing` (section *Kiểm duyệt*) or
`moderation:audit replay --id=`. Full rebuild: `moderation:audit reemit [--case=]`.
A sanction issued without a case uses its own id as `case_id` (audit partition).

```bash
php artisan moderation:audit schema            # create the Cassandra table (explicit, like storylens schema)
php artisan moderation:audit consume --consumer=local --limit=100
php artisan moderation:audit inspect           # stream/pending/DLQ
php artisan moderation:audit timeline --case=<case-uuid>
php artisan moderation:import-sql              # one-time data move; safe to rerun, prints counts
```

**Cutover order (one deploy, back to back).** Until `moderation:import-sql` has run,
legacy SQL sanctions are *not enforced* (helpers read MongoDB only), so:

```bash
php artisan down                       # maintenance mode
php artisan migrate --force            # Mongo indexes + comments.hidden_by_sanction_id varchar(36)
php artisan moderation:import-sql      # copy reports/sanctions, build cases, relink comments, rebuild queue
php artisan moderation:rebuild-queue   # explicit rebuild/counters check (import already rebuilds)
php artisan moderation:audit schema && php artisan moderation:audit consume --limit=100
php artisan up
```

`/admin/processing` (section *Kiểm duyệt*) shows a warning while SQL reports/sanctions
exist without a `legacy_sql_id` copy in MongoDB (the only runtime read of those SQL
tables, inside `SqlImport::unimported()`).

**Consumer and repair are manual for now** (control decision): run
`php artisan moderation:audit consume` and `php artisan moderation:repair` (lease ≥ 60 s,
default 600 s) by hand; the Gate 10 runbook wires them into the process supervisor /
scheduler. `moderation:repair` also unhides comments left hidden by a revoked/inactive
sanction and reports `orphaned_new` / `orphaned_total` sanctions (issued for a case whose
claim was lost; shown on the case page).

Native inspection of one case (replace ids; dev uses `vietfeed`, tests `vietfeed_test`/DB15):

```javascript
// mongosh: current state + outbox of one case and its sanctions
db.getSiblingDB('vietfeed').moderation_cases.findOne({comment_id: '<comment-uuid>'},
  {status: 1, round: 1, version: 1, pending_count: 1, severity: 1, decisions: 1, events: 1})
db.getSiblingDB('vietfeed').sanctions.find({audit_case_id: '<case-uuid>'}, {type: 1, is_active: 1, appeal_status: 1, events: 1})
```

```text
# redis-cli (configured NoSQL DB, physical prefix from config): queue in priority order
ZREVRANGE <configured-prefix>moderation:queue:v1 0 -1 WITHSCORES
HGETALL <configured-prefix>moderation:queue:v1:meta
XRANGE <configured-prefix>moderation:dead:audit:v1 - + COUNT 10
```

```sql
-- cqlsh: the decision chain of one report/sanction case
SELECT occurred_at, type, actor_id, case_version, note
FROM vietfeed.moderation_events_by_case WHERE case_id = <case-uuid>;
```

Tests (`ModerationNosqlTest` and the 9a `Moderation*Test` suites) run only on Mongo
`vietfeed_test`, Redis DB15 (fixture flushes it with the live `getDbNum()` guard),
Cassandra keyspace `vietfeed_test` (the test deletes its partitions) and SQLite `:memory:`.

## Gate 9.5 slice 1 — MongoDB configuration

`Category` and `Source` use the `mongodb` connection and Laravel-generated UUIDv7
string `_id` values. The SQL `categories` and `sources` tables remain for offline
import only; public/admin pages, validation, interests, seeders and RSS workers
read/write MongoDB. Fresh setups use `CategorySeeder` then `SourceSeeder`.

| Collection | Durable indexes / references |
|---|---|
| `categories` | unique `slug`; `name`, boolean `is_active` (default true) |
| `sources` | unique `feed_url`, indexed `category_id` UUID; `url` is deliberately nonunique (several feeds per publisher); prestige, active flag and existing health fields |
| `articles` | `category_id` / `source_id` UUID strings for assigned RSS articles; source/category snapshot UUIDv5s are stable content identities independent of canonical IDs |
| `users` | `favorite_category_ids` embeds category UUID strings; retired SQL interest pivot is unused |

Migrations: `2026_11_18_100001_create_mongodb_config_indexes.php` (idempotent indexes)
and `2026_11_18_100002_widen_source_fetch_logs_source_id.php` (remove SQL FK, widen
to varchar(36), forward-only). Slice 4 moved runtime fetch history to Cassandra.
Article/Source/Category lists batch reads and counts within each store.

The layout composer obtains active navigation categories through `CategoryList`.
The existing default cache store (Redis in the local runtime) holds plain arrays,
not serialized PHP models, under `catalog:categories:active:v1` for 300 seconds.
Category model saves/deletes invalidate it; empty or evicted cache entries rebuild
from MongoDB. Cache failures fall back to a direct Mongo query, including during
layout rendering; failed invalidation does not fail a canonical write. This follows Laravel's disabled PHP-class cache deserialization.
Bulk updates bypassing model events must explicitly call `CategoryList::forget()`.

Optional legacy-data cutover, in maintenance mode with writers/queue workers
quiesced: back up SQL, apply both migrations, run `php artisan config:import-sql`,
verify its counts, then resume workers and HTTP. The command matches slug/feed_url,
inserts UUIDv7 documents once, preserves existing Mongo configuration on rerun,
remaps both numeric/string legacy article references and embedded favorites, and
leaves SQL rows and deterministic UUIDv5 snapshots intact. Slice 4 resolves SQL
fetch-log references during its separate history import.
Snapshot-only Story replay articles retain their existing public eligibility;
replay does not create configuration or automatically publish them.

Choose **ONE** setup path: fresh configuration (seed CategorySeeder, then
SourceSeeder), **OR** import legacy SQL after migrations. **Never seed then import**:
the owner seed changed two Tuổi Trẻ feed URLs, so mixing the paths can create two
sources for the old/new natural keys. Import preflights duplicate SQL category slugs
and source feed URLs before any write, aborting with duplicate IDs only. Resolve
those SQL duplicates before retrying. The seeder remains unchanged.

Model assignment normalizes Category/Source active flags to BSON booleans and
Source prestige/failure/metric fields to integers or null, including SQL-import
values. The Source Health page reads its recent logs from Cassandra as described
in slice 4 below.

This is an offline, rerunnable workflow, not a distributed transaction. Keep
natural keys fixed until import completes and drain legacy integer-ID RSS jobs
before cutover. Cache fill races may be stale for up to 300 seconds; bulk writes
must explicitly invalidate. Repeated active-ID plucks remain a bounded optimization
opportunity. See `.agent/tasks/sql-cutover-config/GATE-REPORT-9.5-1.md` for command
order and evidence. UUIDv5 preservation needs no graph identity remap/rebuild.

## Gate 9.5 slice 2 — comments on MongoDB

`Comment` (roots, replies and the moderation hide fields) uses the `mongodb` connection
with a UUIDv7 string `_id`. The SQL `comments` table stays in place, unused, until the
final slice; only the two import classes (`App\Content\CommentSqlImport`,
`App\Moderation\SqlImport`) read it.

| Collection | Shape | Indexes (`2026_11_18_200001_create_mongodb_comment_indexes.php`, idempotent) |
|---|---|---|
| `comments` | `user_id`, `article_id`, `parent_id` (string or null), `body`, `is_hidden` (bool, default false), `hidden_by_sanction_id` (sanction UUID or null), timestamps, `legacy_sql_id` (imported rows only) | `{article_id, created_at:-1}`, `{parent_id}`, `{user_id}`, partial `{hidden_by_sanction_id}` (filter `$gt: ""`: real sanction ids only, used by `{hidden_by_sanction_id: S}`), `{created_at:-1}`, unique partial `{legacy_sql_id}` |

- `is_hidden` / `hidden_by_sanction_id` are not fillable. The moderation module hides with
  `updateOne({_id, is_hidden ≠ true}, {$set: {is_hidden: true, hidden_by_sanction_id: S}})`
  (no takeover of a hide owned by another sanction) and unhides with
  `updateOne({_id, hidden_by_sanction_id: S}, …)`; the 9b protocol is otherwise unchanged.
- Deleting a comment deletes its replies at any depth (replaces the SQL FK cascade; replies
  first, so an interrupted delete never orphans a reply). Reports and snapshots are kept.
- Moderation references are the comment's string id: `reports.comment_id`,
  `moderation_cases.comment_id` (still unique: the per-comment atomicity point), and
  `events[].comment_id` in cases and sanctions. Events recorded before the cutover keep the
  old SQL integer as `legacy_comment_id`. `moderation:import-sql` (9b) still writes legacy
  integer ids; `comments:import-sql` is the single place that converts integer (or numeric
  string) refs. If an int-keyed and a numeric-string-keyed case exist for one comment (faulty
  earlier run), it merges them into one case (events re-keyed, decisions kept as history,
  pending reports joined) and moves a duplicate report of the same reporter to
  `reports_duplicates` (evidence is never hard-deleted).
- **Cassandra:** `moderation_events_by_case.comment_id bigint` cannot change type and stays
  for history. `moderation:audit schema` adds `comment_ref text` (idempotent
  `ALTER TABLE … ADD`, which tolerates only "column already exists" and rethrows any other
  error); new events write `comment_ref`, legacy integer references (and
  `legacy_comment_id`) write the old `comment_id` value, so a re-emit only adds
  `comment_ref` to an existing row and never changes history. The timeline shows either.
- Counts (home ranking, admin dashboard, admin user list) are one Mongo `$group` per page
  (`App\Support\MongoCounts::group`).

**Cutover order** (maintenance window; legacy data path):

```bash
php artisan down
php artisan moderation:audit consume --limit=100   # repeat until 0: drain events that carry legacy integer ids
php artisan migrate --force                        # comment indexes
php artisan moderation:import-sql                  # only if 9b's import has not run yet (it must run before the comment import)
php artisan moderation:repair                      # finish/release any interrupted case claims before remapping (a claimed case is skipped by the merge)
php artisan comments:import-sql                    # parents first, legacy_sql_id, remap reports/cases/events; prints counts; rerunnable
php artisan moderation:audit schema                # adds comment_ref
php artisan moderation:audit reemit                # republish remapped canonical events (idempotent projection)
php artisan moderation:rebuild-queue
php artisan up
```

`comments:import-sql` prints `hidden_unlinked`: comments still hidden by a legacy sanction id
with no MongoDB copy (also shown as a warning on `/admin/processing`). Rerun
`comments:import-sql` after any later `moderation:import-sql` (it only rewrites legacy
references, so a completed run changes nothing). A legacy reference to a comment that was
already deleted in SQL stays as its numeric id (`refs_unmapped`); the case page shows the
comment as deleted, as before.

**Dev choice:** fresh — re-seed `DemoEngagementSeeder` (and `ModerationDemoSeeder` for the
moderation walkthrough) instead of importing; do not mix both paths.

Native inspection:

```javascript
db.getSiblingDB('vietfeed').comments.find({article_id: '<article-uuid>', parent_id: null}).sort({created_at: -1})
db.getSiblingDB('vietfeed').comments.find({hidden_by_sanction_id: '<sanction-uuid>'}, {is_hidden: 1})
```

```sql
SELECT occurred_at, type, comment_id, comment_ref FROM vietfeed.moderation_events_by_case WHERE case_id = <case-uuid>;
```

Tests: `CommentsMongoTest` (Mongo `vietfeed_test`, Redis DB15 guard, Cassandra keyspace
`vietfeed_test`, SQLite `:memory:` for legacy rows only).

## Gate 9.5 slice 3a — bookmarks, boosts, and activity

MongoDB collections `bookmarks` and `boosts` hold active memberships. Each
document has a UUIDv7 string `_id`, string `user_id` and `article_id`, BSON
`created_at` and `updated_at`, and an integer `legacy_sql_id` on imported rows.
Each collection has a unique `{user_id: 1, article_id: 1}` index, a recent-user
list index `{user_id: 1, created_at: -1}`, and an `{article_id: 1}` count
index. Toggles insert a new membership or delete one by its exact ID.
A conflict retries from current state, so concurrent successful toggles
each perform one change. The response count reads MongoDB.

After the write, the producer sends `BOOKMARKED`, `UNBOOKMARKED`, `BOOSTED` or
`UNBOOSTED` to `interactions:events:v1`. The membership ID is the aggregate
ID and the add event ID. Each removal has its own UUIDv7 event ID. An add
has version 1, a removal has version 2, and the event includes both entity
IDs and an RFC3339 UTC occurrence time. Imported adds carry `backfill: true`.
If Redis rejects the publish, the action still succeeds and the warning
records the exception type.

The existing `trending-v1` consumer group writes two Cassandra projections
before acknowledging a real event. Clustering in both tables is
`occurred_at DESC, event_id ASC`. A retry writes the same primary keys in
both tables, including if the first insert succeeded before an interruption.

| Table | Partition key | Query |
|---|---|---|
| `activity_by_user_month` | `(user_id, year_month)` | Recent activity for one user, reading UTC months newest first |
| `activity_by_article_day` | `(article_id, activity_day)` | Activity for one article in one UTC day, optionally bounded by occurrence time |

Real boosts contribute five Redis ranking points per active membership.
The version marker, scores, and `meta:ready` sentinel share one sorted set,
`interactions:trending:v1`. An unboost removes its membership's contribution
and version marker. A late add checks the exact MongoDB membership before it
can score. A short Redis lock serializes this check and write with other live
projection changes, including rebuild promotion.

Home uses MongoDB counts for the current ranking when the sentinel is absent,
even if new events have recreated a partial sorted set. That request triggers
a best-effort rebuild for following requests. With a ready projection, the
sidebar reads up to 100 positive Redis scores, considers eligible articles
published within 72 hours, and uses MongoDB counts for missing scores. It
also falls back to MongoDB when Redis is unavailable. Card and page counts
always read MongoDB. Web bookmark and boost toggles share a 30-request-per-minute
budget per authenticated user. A limited request gets a JSON 429 and a retry
interval.

### Operation and recovery

Create the Cassandra tables before enabling the scheduled consumer. In a
running Laravel scheduler, it processes batches of up to 100 every five
seconds.

```bash
php artisan interactions schema
php artisan interactions consume --consumer=local-activity --limit=100
php artisan interactions inspect
php artisan interactions replay --id=<dead-letter-stream-id>
php artisan interactions top --limit=10
php artisan interactions reemit
# Pause interaction writes and consumers while replacing the ranking:
php artisan interactions rebuild
# Synthetic Gate 5 demo uses its own key:
php artisan interactions rebuild --synthetic
php artisan interactions top --synthetic
```

`reemit` republishes current adds using the original IDs and timestamps,
so a previously delivered add remains one Cassandra row in each table.
Removed memberships cannot be reemitted from current state. Replay the DLQ
when it still holds the original event. The default rebuild recreates the
ranking from active MongoDB boosts, including boosts that were never
published and excluding memberships whose remove event was lost. Resume
consumers after the rebuild. Use `interactions rebuild` to repair the ranking
after a lost removal. `DemoEngagementSeeder` writes memberships without events,
so run `interactions rebuild` after seeding. Queued adds for previously removed membership
IDs are checked against MongoDB and cannot restore the old score.

For local development, use the fresh seed and re-ingestion path. To retain
legacy SQL interactions in a migration window, back up SQL, pause writes and
consumers, run the MongoDB index migration and `interactions schema`, then
run `php artisan interactions:import-sql` once, in the cutover window
(optionally add `--emit`). The command refuses a repeat if either collection
has a document carrying `legacy_sql_id`. Use `--force` only to resume an
intentional cutover import. A forced rerun can recreate a membership removed
after the initial copy. The import copies by natural key, preserves existing MongoDB documents, and prints
inserted, existing, published and failed counts for each table. If both legacy
timestamps are NULL, the imported document uses its import time. Run the default
rebuild before resuming. Use `interactions reemit` after a backfill
publish failure to recover current additions. Keep the legacy tables
available through the cutover window.

### Native inspection

Use the configured Redis prefix and database for the Redis queries.

```javascript
db.bookmarks.getIndexes()
db.boosts.find({user_id: "<user-id>"}).sort({created_at: -1}).limit(10)
db.boosts.aggregate([{$match: {article_id: "<article-id>"}}, {$count: "active"}])
```

```text
XRANGE <prefix>interactions:events:v1 - + COUNT 5
XPENDING <prefix>interactions:events:v1 trending-v1
ZRANGE <prefix>interactions:trending:v1 +inf (0 BYSCORE REV LIMIT 0 10 WITHSCORES
```

```sql
SELECT occurred_at, event_id, type, article_id, backfill
FROM vietfeed.activity_by_user_month
WHERE user_id = '<user-id>' AND year_month = '2026-11' LIMIT 10;

SELECT occurred_at, event_id, type, user_id, backfill
FROM vietfeed.activity_by_article_day
WHERE article_id = '<article-id>' AND activity_day = '2026-11-18'
  AND occurred_at >= '2026-11-18T12:00:00Z' LIMIT 20;
```

Count-sorted admin lists and the dashboard use whole-collection MongoDB
aggregations with O(collection) work.

The automated checks use MongoDB `vietfeed_test`, Redis DB15 with a live
`getDbNum()` check, Cassandra `vietfeed_test`, and SQLite `:memory:`.

## Gate 9.5 slice 3b — Reading Pass on Redis counters and durable history

Tiers, limits and copy are unchanged: admin unlimited and unrecorded; Pro
unlimited and recorded; verified 15 per rolling 5 hours; unverified account 8
and guest session 5 per Vietnam day. Re-opening an unlocked article never
consumes. Three stores, one job each:

| Store | Holds | Role |
|---|---|---|
| MongoDB `article_unlocks` | One document per (subject, article) | Durable ledger: "already unlocked", rebuild source, transfer, cleanup |
| Redis `reading-pass:v1:*` | One sorted set per subject | Atomic quota decision (Lua), rebuildable projection with TTL |
| Cassandra `unlocks_by_user_month`, `unlocks_by_session_day` | Immutable unlock rows | History by user and UTC month (guest rows by session and UTC day, 30-day TTL) |

### Ledger documents

`_id` UUIDv7 string, `subject` (`u:<user_id>` or `s:<sha256(session id)>`),
`user_id` (string or null), `session_hash` (string or null), `article_id`,
`state` (`pending` or `granted`), BSON `unlocked_at`, `tier`, `origin`
(`unlock`, `transfer`, `import`, `seed`), optional `fallback: true`, optional
`legacy_sql_id`. Raw session ids are never stored. Indexes (migration
`2026_11_18_220001`): unique `{subject, article_id}`, `{subject, unlocked_at}`,
partial `{subject, fallback}`, `{state, unlocked_at}`, `{user_id}`,
`{article_id}`. Admin counts (dashboard, user list, article page) count
`state: granted` only.

### Redis keys and Lua

- `reading-pass:v1:gen` — generation token. Changing it invalidates every window.
- `reading-pass:v1:<subject>:w` — sorted set. `g:<article>` granted,
  `p:<article>` a reservation a rebuild found still pending, scored by unlock
  time in ms; sentinel `~ready:<gen>` at score -1. Members older than 25 h are
  trimmed on each decision; `EXPIRE` 26 h on each grant or rebuild.
- `reading-pass:v1:<subject>:lock` — rebuild/transfer lock (owner token, 10 s).

`DECIDE` runs in one Lua call: no sentinel for the current generation → `-1`
(rebuild); `g:<article>` present → already unlocked; another `p:` older than
60 s → `-2` (resolved from MongoDB, then decided again); otherwise count
`[window start, +inf]` and either deny or `ZADD` the grant. The window start
is passed in (now − 5 h, or 00:00 Vietnam time), so one set serves every tier.
Concurrent requests are serialized by Redis and can never exceed the limit.

A request first reserves its unlock in MongoDB (`pending`), then decides in
Redis, then marks the document `granted` (or deletes it on denial). A rebuild
takes the subject lock, drops the window, reads the ledger (granted within
25 h → `g:`, pending younger than 60 s → `p:`), and publishes the set only if
it still owns the lock and the generation is unchanged. An in-flight grant
whose window was evicted is therefore still counted. An abandoned reservation
is counted for at most 60 s.

**Redis unavailable:** fail-open on the ledger. The reservation is decided by
a MongoDB count of the same window: granted documents plus fresh reservations,
excluding itself. Every request reserves before it counts, so of two
simultaneous fallback requests at least one sees the other: a pure fallback
decision never over-grants (simultaneous requests can at worst both be
refused). A fallback grant is marked `fallback: true`. The next decision for
that subject rebuilds its window first and clears the flags; if that forced
rebuild cannot run (lock busy, Redis failing), the decision uses the MongoDB
count instead of the stale window. The only over-grant is the mixed case:
while one process grants through the fallback, another still reaching Redis
can decide on a window that does not yet contain that grant — at most one
extra unlock per in-flight fallback grant. Navbar, profile and admin allowance
read MongoDB while the window or the generation key is missing or Redis is
down.

A missing `reading-pass:v1:gen` key (evicted or flushed) means "not built":
no window is trusted, and the next rebuild creates a fresh generation with
`SET NX`.

**Same-article race:** a second request for an article whose reservation is
held by a concurrent request re-checks until that reservation is granted
(→ already unlocked, nothing consumed) or released (→ decides normally),
bounded by the rebuild lock wait (1 s) + one Redis timeout (2 s when the
`nosql` connection sets none) + 0.5 s. If the winner still has not settled,
the article is shown without a second record when the window has room
without the winner's reservation, and locked otherwise.

**Guest transfer** (login, register, Google): under the user's lock the user
window is dropped, granted guest documents are copied to the user (a
duplicate article is dropped), guest documents are deleted, both windows are
dropped. The windows are dropped whenever Redis is reachable, even if another
holder had the lock; without the lock (or with Redis down) the moved documents
also get `fallback: true`.

**Cleanup:** `unlocks:cleanup` (daily 02:15) deletes guest documents older
than 30 days and reservations older than one hour. Redis windows expire by
TTL; guest Cassandra rows by TTL.

### History stream and commands

Granted documents publish `UNLOCKED` or `UNLOCK_TRANSFERRED` to
`reading-pass:events:v1` (group `reading-pass-history-v1`, DLQ
`reading-pass:dead:history:v1`). The event id is the ledger `_id`, so
`reemit` republishes identical events and replays overwrite the same rows.
The scheduler consumes batches of 100 every five seconds.

```bash
php artisan reading-pass schema                      # create Cassandra tables
php artisan reading-pass consume --consumer=local --limit=100
php artisan reading-pass inspect
php artisan reading-pass replay --id=<dead-letter-stream-id>
php artisan reading-pass reemit [--user=<user-id>]
php artisan reading-pass history --user=<user-id> --month=2026-11
php artisan reading-pass rebuild                     # new generation; windows rebuild lazily
php artisan unlocks:import-sql [--emit] [--force]    # legacy SQL copy, once
```

`unlocks:import-sql` copies SQL `article_unlocks` in id order by natural key
(subject, article), keeps `unlocked_at` and `legacy_sql_id`, never overwrites
a ledger document, skips rows with neither user nor session, and refuses a
second run without `--force`. It ends with a new generation. The SQL table is
left in place and unused. `DemoEngagementSeeder` writes ledger documents
without events: run `reading-pass reemit` after seeding for history.

### Cutover order

1. Deploy, `php artisan migrate --force` (MongoDB indexes), `php artisan reading-pass schema`.
2. Fresh development path: seed, then `reading-pass reemit`. Legacy path: take
   a read-only SQL backup, pause traffic, `php artisan unlocks:import-sql --emit`, review the totals.
3. Resume traffic and the scheduler; `reading-pass inspect` shows the stream drained.

### Native inspection

```javascript
db.article_unlocks.getIndexes()
db.article_unlocks.find({subject: "u:<user-id>"}).sort({unlocked_at: -1}).limit(20)
db.article_unlocks.countDocuments({state: "granted", unlocked_at: {$gte: ISODate("2026-11-17T17:00:00Z")}})
db.article_unlocks.find({fallback: true})
```

```text
GET <prefix>reading-pass:v1:gen
ZRANGE <prefix>reading-pass:v1:u:<user-id>:w 0 -1 WITHSCORES
XPENDING <prefix>reading-pass:events:v1 reading-pass-history-v1
```

```sql
SELECT unlocked_at, unlock_id, article_id, tier, origin, backfill
FROM vietfeed.unlocks_by_user_month
WHERE user_id = '<user-id>' AND year_month = '2026-11';
```

Known limitations:

- A crash after Redis grants but before the document becomes `granted`
  returns that slot after 60 s if the window is later rebuilt (the user must
  unlock the article again); never an extra unlock.
- Rebuild LOAD re-checks the lock owner and generation, so an evicted rebuild
  lock cannot publish a stale window. The residual is the transfer lock: if it
  expires or is evicted mid-transfer, a rebuild may load a partial ledger, and
  decisions use it until the transfer's final window drop (milliseconds).
- Mixed Redis/fallback operation: at most one extra unlock per in-flight
  fallback grant (see above).
- A same-article loser that outlives the settle bound may read the article
  while the winner is later denied (only if another article took the last
  slot in between); no second record is written.
- A winner that crashed after reserving leaves a pending reservation for up to
  60 s; reopening that article in this window waits for the settle bound and may
  show it without recording or consuming (bounded, needs a crash). The `nosql`
  Redis connection has no read timeout, so a hung Redis can stretch the bound;
  tune `nosql.reading_pass_settle_ms` or set a read timeout in production.
- After a transfer that met a foreign rebuild lock, `allowance()` (navbar,
  profile, admin) can under-count the moved unlocks until the user's next unlock
  decision, which always rebuilds first (decisions stay correct).
- A guest request granted after the transfer started stays under the old guest
  subject (legacy behaviour); the session id is regenerated at login.
- The admin user list makes one quota read per listed user (legacy: one SQL
  count per user).

## Gate 9.5 slice 4 — Source fetch history on Cassandra

MongoDB still holds the Source configuration and current health summary. Cassandra
holds fetch history in two tables with UTC `YYYY-MM-DD` buckets.

| Table | Partition key | Clustering order | Query |
|---|---|---|---|
| `source_fetches_by_source_day` | `(source_id, day)` | `started_at DESC, attempt_id DESC` | One source over a day/week, last 5 on Source Health, last 15 on Source Show |
| `source_fetches_by_day` | `day` | `started_at DESC, attempt_id DESC` | Global recent 20 on Source Health |

Both tables retain attempts for **30 days from the attempt key timestamp**.
Replays and SQL backfill apply only the remaining TTL. Both tables keep
Cassandra deletion markers for 30 days so old snapshots cannot restore cleared
fields during retention. The `attempt_id` is UUIDv7.
A manual retry request keeps one key across queue executions, anchored to the
enqueue time. `fetch_started_at` holds the first execution start and is empty
while queued. The global table keeps one day of traffic per partition, including
diagnostics and retries. Deleted Sources disappear from global results while
their stored rows expire normally. Source Health looks back seven UTC calendar
days, including today, for both its global and per-source history.

RSS writers publish queued, running and outcome snapshots to Redis
`sources:history:events:v1`. Cassandra stays outside the fetch path. The shared
consumer writes both tables and acknowledges delivery after both writes succeed.
Replays reuse the same keys and lifecycle version. The scheduler consumes up to
100 deliveries every five seconds. Each run checks a 35-second budget before
taking another delivery.
With PCNTL, a 45-second alarm terminates stuck socket calls before the one-minute
scheduler mutex expires. Application warnings and retry metadata omit event payloads. The Source admin pages show an unavailable state after a Cassandra
read failure and retain Mongo health and RSS actions.

```bash
php artisan sources:history-manage bootstrap
php artisan sources:history-manage consume --consumer=local --limit=100
php artisan sources:history-manage inspect
php artisan sources:history-manage replay --id=<dead-letter-stream-id>
php artisan sources:history --source=<mongo-source-id> --days=1
php artisan sources:history --source=<mongo-source-id> --days=7
```

The demo command includes today in the UTC calendar-day window. For the same
query in `cqlsh`, substitute a real Mongo Source ID and the required UTC date.
Run the source query per day for a week and read the buckets newest first.

```sql
SELECT started_at, fetch_started_at, attempt_id, status, http_status, duration_ms, error_type
FROM vietfeed.source_fetches_by_source_day
WHERE source_id = '<mongo-source-id>' AND day = '2026-11-18';

SELECT started_at, attempt_id, source_id, status, http_status
FROM vietfeed.source_fetches_by_day
WHERE day = '2026-11-18'
LIMIT 20;
```

### Source history cutover

1. Pause RSS scheduling and manual retry submissions, then drain existing
   `FetchSourceFeedJob` jobs on the old release. They carry SQL log IDs. Take
   a read-only legacy SQL backup if retaining old history.
2. Deploy and run `php artisan sources:history-manage bootstrap` before enabling
   the consumer. Fresh development setups seed Mongo configuration and collect
   new history from subsequent fetches.
3. For legacy data, make Mongo configuration available first using the slice 1
   import if needed. Point the default SQL connection at the legacy database and
   run `php artisan sources:import-history-sql`. The importer accepts already
   remapped Source IDs and resolves numeric IDs through the legacy feed URL.
   Review scanned, written, expired, unresolved-source and missing-timestamp
   totals. The command records the start of the import in Cassandra and requires
   `--force` for an intentional repeat or resume. Source IDs and the legacy input
   must stay stable during the cutover. SQL rows remain untouched.
4. Resume traffic, queue workers and scheduling. Use `inspect` to check pending
   and dead-letter counts, replay if needed, and verify a Source with the demo query.

History can lag behind the Mongo summary while the consumer catches up.
Eviction of the stream or dead-letter queue loses any unconsumed history it
holds. A failed Redis publish also leaves a history gap. An outage that outlasts
the retry budget sends affected deliveries to `sources:history:dead:v1` after
five failed attempts. Once Cassandra recovers, use `inspect` to find dead-letter
IDs and `replay --id=<dead-letter-stream-id>` to requeue them. MongoDB still has
the current health summary. On page requests, the shared CQL driver may wait
for a peer that accepts a connection and never responds. Ordinary connection
failures and CQL errors surface as unavailable on the admin pages.
