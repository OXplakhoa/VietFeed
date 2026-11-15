# Gate 6 PR-A — Implementation

## Implementation

### Plan (written before implementation)

- Content Catalog: `App\Content\ContentCatalog::ingest` validates the frozen fixture, deduplicates URLs, embeds Source/Category snapshots and appends durable versioned Story changes in MongoDB. Unique identities and UUIDv7 are enforced at this boundary.
- Processing Pipeline: extract `App\Processing\StreamConsumer` from the Gate 5 consumer, retain Interaction's public facade and behaviour, and configure separate Story timeline/graph groups. `App\StoryProcessing\StoryPipeline` publishes, projects, records Mongo section state, inspects and recovers work. Redis is dispatch only; Mongo retains replayable history.
- Projection boundaries: `StoryTimeline` and `StoryGraph` own their characteristic write/read behaviour. One Cassandra connection wrapper and one Neo4j client wrapper; explicit Cassandra schema bootstrap. No drivers in commands or read controllers.
- StoryLens: `StoryLens::read` returns Mongo evidence and section states immediately; derived reads happen only for ready sections and failures retain canonical evidence. A thin CLI exposes ingest, schema, bounded consume, inspect, DLQ replay, and Mongo replay.
- Owned paths: new Content/Processing/StoryProcessing/StoryLens modules, thin StoryLens command/controller as needed, tests and fixture; extraction in InteractionStream; config/nosql.php, composer.json/lock, tests/TestCase.php only if necessary; append database documentation; this report. No owner WIP edits.
- Behaviour tests in vertical TDD slices: validated ingest and no-op replay; UUID/event/snapshot identity; independent groups; canonical read pending; Cassandra chronological/idempotent/stale writes; Neo4j topology/idempotent/stale writes; applied-before-ACK recovery; bounded failure/DLQ/inspection/replay; Mongo rebuild after Redis loss; full fixture convergence with unchanged canonical IDs. Container-heavy cases live in Integration; pure/Mongo/Redis cases in Feature.
- Isolation before mutation: no config cache; SQLite :memory:, Mongo vietfeed_test; live Redis DB15 guard; Cassandra vietfeed_test asserted before every write; Neo4j cleanup restricted to this run's IDs. Mutating tests sequential. Capture container restart/OOM counters and memory before/after.

### Initial environment

- Branch: storylens/tracer-pipeline; control base 93daf7894f21ba6c749561fc1ba3e0a2aaefac67.
- Before: Redis 11.15 MiB / 256 MiB; Cassandra 1.443 GiB / 1.5 GiB; Mongo 164.7 MiB / 768 MiB; Neo4j 665.3 MiB / 1 GiB.
- All four restart counters 0, OOMKilled=false. Container start times recorded in tool evidence; Cassandra started 2026-11-14T16:46:53.162357919Z.
- TDD seams are explicitly authorized by the ticket; no further confirmation required. Owner WIP inventoried before edits.

### Implementation result

STATUS: **READY_FOR_REVIEW**. PR-A's 13 outcomes have implementation evidence below.
This is the implementation worker's result; independent Review and Control/owner
acceptance remain separate. No commits, stash, push, PR creation or delegation.
HEAD remains the supplied base; all owned changes are uncommitted.

### Decisions and reasons

1. **Small domain modules.** ContentCatalog owns validated fixture ingest, StoryPipeline
   owns dispatch/state/recovery, StoryTimeline and StoryGraph own projection writes
   and characteristic reads, and StoryLens owns canonical-first reads. The command
   calls these interfaces; even its test-store safety check is delegated to the
   pipeline, with no driver access in the command. Existing live RSS fetching and
   SQL Source/Category behavior stay outside this tracer entry point.
2. **Canonical identity and snapshots.** Article/Story/event IDs are UUIDv7. Unique
   Article URL and partial Story story_key indexes enforce identities. Article embeds
   source/category snapshots; Story embeds article IDs, counters, aggregate version,
   immutable event envelopes and section processing state. Existing RSS Articles are
   adopted with their original IDs when first attached to a Story. Each canonical
   Article attachment is one versioned Story aggregate change.
3. **Interim Source/Category keys.** Deterministic UUIDv5 in the URL namespace over
   `vietfeed:source:<exact feed_url>` / `vietfeed:category:<slug>`: these are stable
   across SQL installation IDs and display-name edits. Feed identity matches the
   existing SourceSeeder updateOrCreate key. Migration must replace these with
   canonical UUIDv7 using an explicit old-key mapping and rebuild; no SQL model or
   owner SourceSeeder edit was required.
4. **Events and delivery.** Type `story.article_added.v1`; envelope fields event_id,
   aggregate_id, aggregate_version, occurred_at (UTC milliseconds), article_id.
   Stream `stories:events:v1`; groups `story-timeline-v1`, `story-graph-v1`; DLQs
   `stories:dead:{timeline,graph}:v1`; errors `stories:errors:{timeline,graph}:v1`.
   The shared core is extracted from InteractionStream, not copied. Story ACK/DLQ
   keeps the input payload so either group can read independently. Interaction's
   facade keeps original deletion semantics. Shared checked Redis results also
   cover publish, group creation and inspection. Five attempts and paged pending
   recovery/backoff are retained; default base is 1000 ms, test CLI uses 1 ms.
5. **Cassandra design.** Explicit bootstrap command creates configured keyspace with
   SimpleStrategy/RF1 and `story_events_by_story`. Columns: story_id uuid,
   occurred_at timestamp, event_id uuid, aggregate_version bigint, type text,
   article_id uuid. PK `((story_id), occurred_at, event_id)`; clustering DESC on
   occurrence time then event ID. Redelivery rewrites the same key. Newer events
   project the durable prefix, preserving history if versions arrive out of order.
   `mroosz/php-cassandra` moved to require at the unchanged ^1.2 constraint. All
   locked dependency objects and versions match HEAD; only its package class moved.
6. **Neo4j design.** One Cypher transaction MERGEs UUID identities and PART_OF/FROM/IN
   relationships. No bodies or internal node IDs. Graph version guards lower
   snapshots; Mongo projected_version guards duplicate/stale consumer work.
   Characteristic read returns Sources covering a Story with Article counts.
7. **Client behavior.** One construction site per client. Cassandra query returns
   typed Result; INSERT/DELETE expects VoidResult, SELECT expects RowsResult.
   Repeated IF NOT EXISTS DDL can return void, not SchemaChangeResult (observed and
   accommodated). Neo4j returns CypherList, with empty results allowed. Invalid CQL
   and Cypher surface typed underlying exceptions through sanitized operational
   wrappers. Connection failures do not print credentials in CLI/DLQ.
8. **Mongo replay and state.** Story commit retains original event IDs, versions and
   times before dispatch. reemit resets both section markers to pending/0 and
   republishes durable events, repairing a lost stream or deleted projections,
   including when old markers had said ready. It requires paused writers/consumers.
   Duplicate ingest stays a no-op even after a rejected publish; explicit reemit
   repairs dispatch. Only a projection's own Mongo section is updated. Evidence is
   returned before any derived read; a derived outage preserves evidence.
9. **Tests.** Feature/StoryCatalogTest uses real guarded Mongo/Redis, so it belongs in
   composer run test. Integration/StoryProjectionsTest owns Cassandra/Neo4j behavior,
   fault, replay and rebuild tests, invoked explicitly. There are no new Mongo
   collections and no TestCase teardown changes. Integration Source/Category keys
   are unique per run; graph cleanup is restricted to tracked UUIDs. Tiny Cassandra
   partitions are removed under vietfeed_test only. Existing spikes keep their
   prior separate vietfeed_spike test keyspace and documented residue behavior.
10. **Frozen real evidence.** Network fetch succeeded for the seeded VnExpress,
    Tuổi Trẻ and Thanh Niên world feeds; the fixture keeps ten selected items from
    the first two. Five items per human-assigned Story, both covered by two Sources.
    No fabricated data or full bodies. Capture metadata and the Tuổi Trẻ timezone
    interpretation are embedded in the versioned fixture. This is human grouping,
    not clustering or a truth/credibility judgment.

### Acceptance — required outcomes

| # | Outcome | Result | Evidence |
|---|---|---|---|
| 1 | Frozen real-RSS fixture | PASS | tests/Fixtures/storylens-rss-v1.json: ten items, two human groups, two Sources each, capture/feed metadata, <=200-character excerpts; real HTTP fetch succeeded |
| 2 | Content Catalog ingest | PASS | ContentCatalog; Feature tests assert 10 Articles/2 Stories, UUIDv7, snapshots/counters, URL unique constraint, original identity adoption and no-op second ingest |
| 3 | Versioned Redis events/shared core | PASS | StoryPipeline + extracted Processing/StreamConsumer; immutable Mongo envelopes; distinct groups; original 29 Streams tests pass after extraction |
| 4 | Mongo-derived replay | PASS | publish-failure Feature test retains evidence; full Integration rebuild deletes only owned projections and guarded Redis DB15, then reemit restores both stores |
| 5 | Cassandra timeline | PASS | explicit storylens schema; partition characteristic read, descending history, duplicate/stale tests; unchanged driver version promoted to runtime |
| 6 | Neo4j topology | PASS | graph test verifies 5 Article PART_OF/IN paths, one Category, two covering Sources, no bodies; deterministic interim keys documented |
| 7 | Idempotent/version-aware consumers | PASS | both projections ignore duplicate/stale versions; real leased applied-before-ACK deliveries recover without extra timeline facts/graph Articles |
| 8 | Mongo processing state | PASS | pending before consumers; per-section ready/projected version after convergence; real graph failure gives failed while timeline stays ready |
| 9 | Canonical read before projection | PASS | StoryLens::read + JSON CLI; pending evidence test and real graph-outage evidence test; no SQL Source/Category joins |
| 10 | Failure recovery without re-ingest | PASS | Integration real port-refusal fault and separate-process CLI proof: five attempts → DLQ → remove fault → replay → ready; canonical counts/IDs unchanged |
| 11 | Thin CLI | PASS | ingest/schema/consume/inspect/replay/reemit/read; command inspection/recovery tested; real CLI all exit codes captured below |
| 12 | Convergence | PASS | full ten-item fixture reaches ready/ready, versions=5/5 per Story; final run 1 observed 0.223 s locally; no general performance claim |
| 13 | Documentation | PASS | appended Gate 6 section in docs/database/nosql-local.md: model, schema, keys, commands, four native queries, recovery, isolation and limits |

### TDD and diagnostic evidence

The authorized seams were recorded before implementation. The main vertical slices
were implemented following failing tests; validation, envelope/index, invalid-query
and unsafe-keyspace cases also provide supplemental behavioral coverage.

| Slice / command | Observed red result | Green evidence |
|---|---|---|
| phpunit tests/Feature/StoryCatalogTest.php (initial catalog slice) | exit 2, 1 test / 7 assertions, ContentCatalog missing | initial slice exit 0, 1 / 63; final file 6 / 161 |
| phpunit tests/Integration/StoryProjectionsTest.php (timeline) | exit 2, 1 / 0, StoryTimeline missing | final Integration file exit 0, 7 / 153 |
| same file after adding graph seam | exit 2, 2 / 25, StoryGraph missing | graph/timeline initial green exit 0, 2 / 34 |
| same file after adding applied-before-ACK recovery | exit 2, 3 / 44, consume missing | exit 0, 3 / 53 before later test additions |
| same file --filter=test_graph_outage | exit 2, 1 / 7, storylens command missing | exit 0, 1 / 30 after CLI/recovery implementation |
| same file --filter=test_full_fixture | exit 1, 1 / 25, reemit command not implemented; later exit 2, 1 / 14 for missing derived section data | exit 0, 1 / 43 with convergence and destructive isolated rebuild |
| Feature file --filter=test_existing_rss | exit 2, 1 / 10, missing snapshot on adopted Article | final Feature file exit 0, 6 / 161 |

Diagnostic/harness corrections: the first capture script did not understand Tuổi
Trẻ's timezone-free publication format and the first fixture-dependent test exited
2 with a missing file (1 / 7). The corrected capture succeeded. Mongo already had
a full unique original_url index; requesting a different partial index under its
same name failed (exit 2, 1 / 7); the catalog now matches that existing definition.
A repeated Cassandra DDL exposed VoidResult (exit 2, 1 / 0), fixed as above. Early
timeline teardown ran before the Neo4j wrapper existed (exit 2, 1 / 14). The outage
inspection test initially expected two separate output fragments from one Artisan
JSON emission (exit 1, 1 / 21); capturing Artisan output once fixed the harness.
None of those failed runs are counted as verification passes.

### Final verification (after the final PHP change)

Each row was a separate process invocation, awaited to its true exit code. All
mutating tests ran sequentially under the exclusive slot. No tail/tee or pipeline
masked the status. New files each ran three consecutive times with no other test
between that file's runs. These final runs supersede earlier intermediate passes.

| Command | Exit | Counts/result |
|---|---:|---|
| vendor/bin/phpunit tests/Feature/StoryCatalogTest.php — run 1 | 0 | 6 tests / 161 assertions |
| same exact command — run 2 | 0 | 6 / 161 |
| same exact command — run 3 | 0 | 6 / 161 |
| vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php — run 1 | 0 | 7 / 153; observed full-fixture convergence 0.223 s |
| same exact command — run 2 | 0 | 7 / 153 |
| same exact command — run 3 | 0 | 7 / 153 |
| vendor/bin/phpunit tests/Feature/RedisStreamsTest.php | 0 | 29 / 495 |
| vendor/bin/phpunit tests/Feature/RedisQueueTest.php | 0 | 3 / 42 |
| vendor/bin/phpunit tests/Feature/RedisSessionTest.php | 0 | 4 / 47 |
| composer run test | 0 | 93 / 1171; config:clear completed |
| vendor/bin/phpunit tests/Integration/MongoConnectionTest.php tests/Integration/MongoAuthDoubleTest.php | 0 | 3 / 19 |
| vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php | 0 | 4 / 35 |
| vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php | 0 | 3 / 18 |
| composer check-platform-reqs | 0 | PHP 8.4.27; ext-mongodb 2.5.2; ext-redis 6.3.0; all requirements pass |
| python3 /tmp/vietfeed-storylens-cli-proof.py | 0 | 20 separate subprocesses, all exit 0; details below |

Exact final style command (all 13 owned PHP files):

```bash
vendor/bin/pint --test app/Content/ContentCatalog.php app/Processing/StreamConsumer.php app/Interaction/InteractionStream.php app/StoryProcessing/CassandraClient.php app/StoryProcessing/Neo4jClient.php app/StoryProcessing/StoryGraph.php app/StoryProcessing/StoryPipeline.php app/StoryProcessing/StoryTimeline.php app/StoryLens/StoryLens.php app/Console/Commands/StoryLens.php config/nosql.php tests/Feature/StoryCatalogTest.php tests/Integration/StoryProjectionsTest.php
```

Dependency preparation commands both exited 0: composer update --lock --no-install
--no-scripts --no-interaction, then composer update mroosz/php-cassandra
--minimal-changes --no-install --no-scripts --no-interaction. The second invocation
was needed to move the locked package from packages-dev to packages. Unrelated
refreshed lock metadata was restored from HEAD; a final equality check confirmed
all package metadata/versions unchanged and the sole promotion authorized above.

### Real CLI recovery proof

The temporary proof runner invoked real php artisan processes, not Artisan mocks.
Its helper bootstrapped Laravel only to assert isolation, capture IDs/counts and
clean the exact owned IDs/partition. The fixture was a one-item subset of the real
capture, with per-run Story/Source/Category identity suffixes for graph isolation.
Process-local overrides were those documented in nosql-local.md, with
STORY_RETRY_MS=1. The active .env was not edited. Each failing consumer invocation
alone received NEO4J_BOLT_PORT=1; normal invocations used the configured working
port. Handled projection failures intentionally return exit 0 after recording
retry/DLQ; assertions inspect the actual failure state and five-attempt envelope.

| Separate subprocess | Exit |
|---|---:|
| `php /tmp/vietfeed-storylens-proof-helper.php setup` | 0 |
| `php artisan storylens schema` | 0 |
| `php artisan storylens ingest --fixture=/tmp/vietfeed-storylens-cli-fixture.json` | 0 |
| `php /tmp/vietfeed-storylens-proof-helper.php snapshot` | 0 |
| `php artisan storylens read --story=01a1db46-e5a6-7043-9f33-74b4504607e0` | 0 |
| `php artisan storylens consume --section=timeline` | 0 |
| `NEO4J_BOLT_PORT=1 php artisan storylens consume --section=graph --limit=1` | 0 |
| `NEO4J_BOLT_PORT=1 php artisan storylens consume --section=graph --limit=1` | 0 |
| `NEO4J_BOLT_PORT=1 php artisan storylens consume --section=graph --limit=1` | 0 |
| `NEO4J_BOLT_PORT=1 php artisan storylens consume --section=graph --limit=1` | 0 |
| `NEO4J_BOLT_PORT=1 php artisan storylens consume --section=graph --limit=1` | 0 |
| `php artisan storylens inspect --section=graph --story=01a1db46-e5a6-7043-9f33-74b4504607e0` | 0 |
| `php artisan storylens read --story=01a1db46-e5a6-7043-9f33-74b4504607e0` | 0 |
| `php artisan storylens replay --section=graph --id=1794680220343-0` | 0 |
| `php artisan storylens consume --section=graph` | 0 |
| `php artisan storylens consume --section=timeline` | 0 |
| `php artisan storylens read --story=01a1db46-e5a6-7043-9f33-74b4504607e0` | 0 |
| `php /tmp/vietfeed-storylens-proof-helper.php snapshot` | 0 |
| `php artisan storylens inspect --section=graph --story=01a1db46-e5a6-7043-9f33-74b4504607e0` | 0 |
| `php /tmp/vietfeed-storylens-proof-helper.php cleanup /tmp/vietfeed-storylens-cli-owned.json` | 0 |

Observed: before any consumer, one evidence Article and pending sections; after
five graph failures, pending=0, graph DLQ=1 with attempts=5, timeline ready, graph
failed, evidence still present. Removing the fault and replaying produced both
sections ready, DLQ=0 and unchanged canonical IDs/counts (one Article, one Story).
The timeline group consumed the shared replay as an idempotent duplicate. Final
cleanup left zero test Articles/Stories and removed only owned graph UUIDs and
the owned vietfeed_test timeline partition; Redis cleanup used the live DB15 guard.
Temporary runner/helper/log paths are diagnostic artifacts under /tmp, not proposed
commit paths. The permanent Integration test and documented CLI steps reproduce
the same domain/CLI recovery contract.

### Final operational checks and memory

| Command | Exit | Result |
|---|---:|---|
| Exact Pint --test invocation above | 0 | All 13 owned PHP files pass |
| git diff --check | 0 | No whitespace errors |
| ./scripts/nosql-health.sh | 0 | Mongo/Cassandra/Neo4j/Redis: 4/4 READY |
| docker stats --no-stream (before and after) | 0 / 0 | Figures below |
| docker inspect restart/OOM/start-time query (before and after) | 0 / 0 | All restart=0, OOMKilled=false; same container start times |
| composer validate --no-check-publish | 0 | composer.json and lock valid |
| Owner WIP / dependency-object hash comparison | 0 | Five original tracked WIP files byte-identical; all dependency objects/versions identical |

| Container | Before | After | Limit |
|---|---:|---:|---:|
| Redis | 11.15 MiB | 15.87 MiB | 256 MiB |
| Cassandra | 1.443 GiB | 1.450 GiB | 1.5 GiB |
| Mongo | 164.7 MiB | 165.1 MiB | 768 MiB |
| Neo4j | 665.3 MiB | 613.2 MiB | 1 GiB |

Highest sampled Cassandra usage during implementation was 1.469 GiB. No restart,
OOM kill, infrastructure change or stop condition occurred. No active .env,
Compose, container caps or runtime dependency versions were changed. The final
health check was after all mutating tests and the real CLI proof. New test data
was tiny and its Cassandra partitions were cleaned after each run. The existing
Cassandra spike retains its prior small random partitions, as before this gate.

Isolation was verified by TestCase before tests, live Redis DB15 guards before
flushes, and keyspace checks before new Cassandra operations; the real CLI also
refused to proceed without isolated stores. No config cache remained. No new Mongo
collections were introduced. Neo4j cleanup used only per-run tracked UUIDs; no
unrestricted graph delete, dev keyspace write, volume deletion, FLUSHALL or dev
migrate:fresh was executed.

### Owned files created / changed

Created:

- app/Content/ContentCatalog.php
- app/Processing/StreamConsumer.php
- app/StoryProcessing/CassandraClient.php
- app/StoryProcessing/Neo4jClient.php
- app/StoryProcessing/StoryGraph.php
- app/StoryProcessing/StoryPipeline.php
- app/StoryProcessing/StoryTimeline.php
- app/StoryLens/StoryLens.php
- app/Console/Commands/StoryLens.php
- tests/Feature/StoryCatalogTest.php
- tests/Integration/StoryProjectionsTest.php
- tests/Fixtures/storylens-rss-v1.json
- .agent/tasks/storylens-tracer/GATE-REPORT-6A.md

Changed:

- app/Interaction/InteractionStream.php
- composer.json
- composer.lock
- config/nosql.php
- docs/database/nosql-local.md (append only)

The task contract was read, not edited. No tests/TestCase.php change was needed.
The three older STATUS files, CLAUDE.md and SourceSeeder.php matched their initial
byte hashes. Other untracked task packets, .pi/, docs/agents/ and pi-warden.md were
not written. Do not include those existing owner changes in PR-A.

### Limitations and deferred work

- This is a tiny local single-writer tracer. Same-section concurrent workers,
  concurrent ingest/reemit, large histories, long-running lease renewal, outages
  during Mongo state writes and large pending lists are not stress-tested. Reemit
  is an explicit operation requiring paused writers/consumers. Stream entries are
  retained for independent groups; retention/compaction is deferred.
- Mongo Article and Story writes are separate. The Story embeds durable event
  history in its aggregate commit; there is no automatic outbox relay or distributed
  transaction. Redis publish failures are visible and repairable through reemit.
  A pre-Story interruption can be reconciled by re-running fixture ingest using
  the already-assigned Article/Story identity. Automatic crash reconciliation is
  not claimed. Embedded event history is not a long-term high-volume design.
- Shared Lua moves retain Gate 5's accepted non-failure-atomic limitation: late
  server/ACL/transport faults can leave duplicate moves, DLQ entries or replays.
  Projection primary keys/MERGE and version checks tolerate duplicate facts; this
  does not claim idempotent move-marker implementation. Missing Redis payloads
  remain inspectable; canonical tracer history can be re-emitted separately.
- Bounded consume reports deliveries handled, including recorded failures; exit 0
  is not by itself proof a section is ready. Inspection/read state and DLQ are the
  evidence. Inspection is bounded to 1000 pending/100 DLQ entries. A transient
  derived-read failure is returned with canonical evidence without rewriting the
  Mongo worker's last recorded version.
- Source/Category UUIDv5 keys and human story_key grouping are explicitly interim.
  No Source/Category SQL migration, real clustering, AI, entities/aspects, locks,
  Billing, auth-token work, Source Health lock or fifth store was introduced.
- Carried follow-ups remain: Cassandra-backed interaction replay for trending;
  idempotent move markers for Gate 5 Lua; distributed fetch/projection lock.
  PR-B owns progressive Blade rendering/polling, admin processing/retry UI and the
  full UI demo. DBeaver/manual UI acceptance was not run; the evidence here is
  domain/CLI/native-client testing. Independent Review and owner acceptance remain
  pending; no MANUAL_PASS, merge, deployment or production readiness is claimed.

### PROPOSED ATOMIC COMMIT GROUPING

Control owns committing. These are exact paths; exclude all owner WIP and older
packets. The final tree is green; intermediate groups have not been separately
checked out/tested. Group 1 preserves the existing API; group 2 contains the whole
new executable vertical slice plus its tests so it does not introduce missing
classes between commits.

1. **refactor(streams): extract shared bounded consumer core** →
   - app/Processing/StreamConsumer.php
   - app/Interaction/InteractionStream.php
2. **feat(storylens): add canonical RSS projections and replay recovery** →
   - composer.json
   - composer.lock
   - config/nosql.php
   - app/Content/ContentCatalog.php
   - app/StoryProcessing/CassandraClient.php
   - app/StoryProcessing/Neo4jClient.php
   - app/StoryProcessing/StoryGraph.php
   - app/StoryProcessing/StoryPipeline.php
   - app/StoryProcessing/StoryTimeline.php
   - app/StoryLens/StoryLens.php
   - app/Console/Commands/StoryLens.php
   - tests/Fixtures/storylens-rss-v1.json
   - tests/Feature/StoryCatalogTest.php
   - tests/Integration/StoryProjectionsTest.php
3. **docs(storylens): record Gate 6A queries recovery and evidence** →
   - docs/database/nosql-local.md
   - .agent/tasks/storylens-tracer/GATE-REPORT-6A.md

## Review-fix round 1

STATUS: **READY_FOR_REVIEW** (implementation worker only; independent re-review and
Control/owner acceptance pending). Branch `storylens/tracer-pipeline`, HEAD
`93daf78`; work remains uncommitted. This appendix supersedes earlier claims about
race safety, Cassandra spike isolation, verification counts and commit grouping.

### Findings and changes

| Finding | Result | Change and regression evidence |
|---|---|---|
| P1 stale readiness / lost progress | RESOLVED | `app/StoryProcessing/StoryPipeline.php:58` uses a current-document projected_version guard for both success and failure. Success uses a Mongo aggregation update to decide ready versus pending from current Story.version and set only owned section fields; failure cannot overwrite equal/newer success or change projected_version. `app/Content/ContentCatalog.php:77` initializes a new Story once; existing attachments use version-filtered $push/$inc/$set, preserving consumer projected_version. A conflicting aggregate write throws for explicit rerun. `tests/Integration/StoryProjectionsTest.php:125`, `:147`, `:172` inject deterministic projection/Article-save interleavings for BOTH sections, with no sleeps: v1 completion against v2 stays pending until v2 projects; late success/failure preserves v2; ingest preserves progress completed after its Story read and marks the advanced version pending. |
| P2 silent partial projection | RESOLVED | `app/StoryProcessing/StoryGraph.php:14` deduplicates expected Article IDs and validates the complete canonical prefix before any graph write; `:37` checks the returned projected count. Mismatch throws through consumer retry/DLQ and failed state. `app/StoryProcessing/StoryTimeline.php:26` validates the same distinct prefix before CQL writes; every INSERT requires asVoidResult(), so a failed statement cannot silently succeed. `tests/Integration/StoryProjectionsTest.php:192` deletes a canonical Article and proves both groups fail and reach DLQ after five attempts, retaining projected_version=1; `:208` proves a graph query projecting 0 of 2 Articles is rejected. |
| C1 legacy Gate 3 Story | RESOLVED | `app/StoryLens/StoryLens.php:16` defaults absent article_ids to empty; `app/StoryProcessing/StoryPipeline.php:80` normalizes missing processing to pending/0 and suppresses stale ready markers for read/inspect. `:34`, `:39`, `:98`, `:107` make apply/inspect/reemit safe for absent history/version/processing. Unknown events remain invalid rather than inventing a projection. `tests/Feature/StoryCatalogTest.php:34` creates a Story with only Gate 3 title/status and covers read, inspect, empty reemit and rejected apply. |
| P3 non-atomic persistence windows | RESOLVED within assigned recovery design | `tests/Feature/StoryCatalogTest.php:55` interrupts Story saving after Article persistence, verifies no Story/event dispatch, then reruns with the SAME Article and assigned Story UUID and exactly one attachment event. `tests/Integration/StoryProjectionsTest.php:222` rejects Redis XADD after a second attachment commits; canonical evidence remains readable, both sections pending, duplicate ingest no-ops, reemit+both consumers repairs dispatch and reaches ready with unchanged IDs/counts. The separate-write design in `app/Content/ContentCatalog.php:57` / `:77` / `:101` is preserved. |
| DOC retention and readiness wording | RESOLVED | `docs/database/nosql-local.md:347` states current-document readiness, snapshot/read windows and the deliberate paused-rebuild reset exception. `:376` states input remains after ACK by both groups, is never trimmed, and replay/reemit append; follow-up is safe trimming after BOTH groups pass entries, accounting for pending work, with Mongo re-emission as recovery. `:448` specifies the exact repair command for each persistence window. |

The graph/timeline classes are extensible solely to expose the existing project()
boundary to deterministic test subclasses; native client execution still runs
inside these tests. No delegation or fake-only success path was used.

Normal consumer and ingest operations never lower projected_version. A paused
operator `reemit` intentionally resets both sections to pending/0 so deleted derived
data is rebuilt. The reset at `app/StoryProcessing/StoryPipeline.php:115` updates both
sections atomically with a version-snapshot filter and clears obsolete errors;
it refuses an aggregate version changed since loading. This filter is not a
distributed lock; writers AND consumers must still be paused for rebuild.

### Red -> green evidence

Regression tests were written before behavior fixes. The initial non-final class
change only opened the projection seam; it did not change projection behavior.

| Invocation | True exit | Observed result |
|---|---:|---|
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php --filter 'inflight\|late_success\|ingest_does\|missing_canonical\|count_mismatch'` before fixes | 1 | 5 tests / 53 assertions, 5 failures: stale v1 reported ready against v2; late success lowered progress 2 -> 1; ingest left ready rather than pending after advancing; missing canonical Article accepted; Cypher count mismatch accepted. |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php --filter 'gate3\|interruption'` before fixes | 2 | 2 tests / 25 assertions: legacy read threw count(null); Article-to-Story injected-interruption/recovery test already passed. |
| Same Integration filter after P1/P2 fixes (before expanding to both sections) | 0 | 5 tests / 62 assertions. |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php --filter 'gate3\|interruption\|publish_failure'` after C1 fix | 0 | 3 tests / 47 assertions. |
| Expanded final Integration file (both sections, late failure, both persistence windows) | 0 | 17 tests / 282 assertions on each of three consecutive final runs below. |

P3 is an intentional recoverable design, not a newly changed atomicity guarantee.
Its interruption was observed and asserted, then recovery passed on the existing
design; there was no pre-fix regression failure to claim. DOC is a documentation
correction, with executable recovery evidence above rather than a synthetic red
documentation test. The late-failure branch and graph race counterparts were added
to strengthen the final interleaving coverage; the original red run stopped the
late-success/failure test at its first (late-success) assertion.

### Final verification

Each command below ran separately and sequentially, awaited to its actual process
exit code; no shell pipeline/tail masked a status. These runs followed the final
PHP edits and formatting. Stores remained isolated throughout: Mongo vietfeed_test,
SQLite :memory:, live guarded Redis DB15, Cassandra vietfeed_test and graph cleanup
restricted to tracked per-run UUIDs.

| Command | Exit | Counts/result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php (run 1)` | 0 | 8/8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php (run 2)` | 0 | 8/8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php (run 3)` | 0 | 8/8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php (run 1)` | 0 | 17/17 tests, 282 assertions |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php (run 2)` | 0 | 17/17 tests, 282 assertions |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php (run 3)` | 0 | 17/17 tests, 282 assertions |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` | 0 | 29/29 tests, 495 assertions |
| `composer run test` | 0 | 95/95 tests, 1203 assertions |
| `vendor/bin/phpunit tests/Integration/MongoConnectionTest.php tests/Integration/MongoAuthDoubleTest.php` | 0 | 3/3 tests, 19 assertions |
| `vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php` | 0 | 4/4 tests, 35 assertions |
| `vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php` | 0 | 3/3 tests, 32 assertions |
| `vendor/bin/pint --test <14 exact PHP paths listed below>` | 0 | PASS (14 files) |
| `git diff --check` | 0 | No whitespace errors |
| `./scripts/nosql-health.sh` | 0 | 4/4 READY |

Exact Pint command (all 14 PHP files created/changed in PR-A, including the isolation
correction to the inherited Cassandra spike):

```bash
vendor/bin/pint --test app/Content/ContentCatalog.php app/Processing/StreamConsumer.php app/Interaction/InteractionStream.php app/StoryProcessing/CassandraClient.php app/StoryProcessing/Neo4jClient.php app/StoryProcessing/StoryGraph.php app/StoryProcessing/StoryPipeline.php app/StoryProcessing/StoryTimeline.php app/StoryLens/StoryLens.php app/Console/Commands/StoryLens.php tests/Feature/StoryCatalogTest.php tests/Integration/StoryProjectionsTest.php tests/Integration/CassandraSpikeTest.php config/nosql.php
```

The three final local fixture convergence observations were 1.665 s, 1.349 s,
0.472 s. These are local observations, not a performance guarantee.

The requested inherited `CassandraSpikeTest` previously hard-coded vietfeed_spike.
To honor this round's stricter hard rule, `tests/Integration/CassandraSpikeTest.php:19`
now targets vietfeed_test, `:34` asserts testing environment and allowed keyspace
before connecting, and `:23` deletes only the created story_timeline partitions.
No dev-keyspace writes or keyspace/table drops occurred.

### Docker memory, restart and OOM observations

Both `docker stats --no-stream --format '{{.Name}} {{.MemUsage}}'` and
`docker inspect --format '{{.Name}} restart={{.RestartCount}} OOM={{.State.OOMKilled}} started={{.State.StartedAt}}' vietfeed-mongo vietfeed-redis vietfeed-cassandra vietfeed-neo4j`
returned exit 0 before and after the mutating tests.

| Container | Before round | After tests | Limit | Restart before/after | OOM before/after |
|---|---:|---:|---:|---|---|
| Redis | 15.86 MiB | 15.86 MiB | 256 MiB | 0 / 0 | false / false |
| Cassandra | 1.449 GiB | 1.450 GiB | 1.5 GiB | 0 / 0 | false / false |
| Mongo | 165.3 MiB | 165.1 MiB | 768 MiB | 0 / 0 | false / false |
| Neo4j | 613.9 MiB | 619.4 MiB | 1 GiB | 0 / 0 | false / false |

All start timestamps were unchanged: Mongo 2026-11-14T16:46:53.160790377Z;
Redis 2026-11-14T16:46:53.161208044Z; Cassandra
2026-11-14T16:46:53.162357919Z; Neo4j 2026-11-14T16:46:53.161543335Z.
These are point samples; no claim of continuous peak-memory measurement.
Final health was 4/4 READY. No stop condition occurred.

SHA256 comparison against the round-entry tracked-WIP snapshot found byte-identical
infra-cassandra-spike/STATUS.md, mongo-foundation/STATUS.md,
neo4j-foundation/STATUS.md, CLAUDE.md, SourceSeeder.php, InteractionStream.php,
composer.json, composer.lock and config/nosql.php. Only the owned database-doc
changes differ among files already modified at round entry. Other owner packets,
.pi/, docs/agents/ and pi-warden.md were not edited. No dependencies, Compose or
.env files changed in this round; no commits, stash or push.

### Updated acceptance table

These are implementation verification results, not independent Review or manual
acceptance. Earlier fixture provenance remains as recorded; no recapture was
needed in this review-fix round.

| # | Required outcome | Implementation evidence after review fixes |
|---|---|---|
| 1 | Frozen real RSS fixture | PASS — unchanged ten-item/two-Story fixture and provenance; repeat ingest tests pass. |
| 2 | Canonical Catalog | PASS — stable IDs, dedupe and exactly one event per attachment, conditional field-level Story updates, interruption recovery. |
| 3 | Versioned events/shared Stream core | PASS — durable event prefixes and separate groups; RedisStreams 29/29. |
| 4 | Mongo replay | PASS — lost publish and lost projections repaired with explicit reemit, canonical identities unchanged. |
| 5 | Cassandra timeline | PASS — prefix validation, successful CQL result checks, idempotence/history; test writes only vietfeed_test. |
| 6 | Neo4j graph | PASS — canonical topology plus pre-write complete-evidence check and checked projected count. |
| 7 | Version-aware/idempotent consumers | PASS — duplicate/stale/applied-before-ACK plus deterministic interleavings on both sections. |
| 8 | Mongo processing state | PASS — atomic current-version decision; ingest preserves projected_version; late failures cannot erase newer success. Explicit paused rebuild reset documented. |
| 9 | Canonical read | PASS — evidence before projection/during graph failure; Gate 3 title/status-only Story safely pending/empty. |
| 10 | Retry/DLQ recovery | PASS — real outage replay and missing-evidence DLQ, no re-ingest for durable-history repair. |
| 11 | Thin CLI | PASS — existing CLI integration coverage including inspect/replay/reemit; precise boundary-repair commands documented. |
| 12 | Convergence | PASS — both sections ready for full fixture; three final Integration runs 17/17. |
| 13 | Documentation | PASS — recovery windows, exact readiness meaning, untrimmed retention and follow-up recorded. |

### Limitations and remaining work

No assigned finding remains partially implemented within the accepted tracer scope.
P3 remains recoverable rather than atomic by explicit Control direction. Ingest is
single-writer; an aggregate CAS conflict requires rerunning the same fixture.
There is no automatic outbox relay, crash reconciliation, distributed projection
lock, lease-renewal redesign or transactional multi-store read. A projection can
finish before its Mongo success write; read state may stay pending/failed until a
retry records success. Mongo may advance after a read snapshot; derived reads may
change independently. Concurrent canonical Article deletion after preflight
validation and arbitrary external graph modifications are not coordinated.

Reemit requires paused writers/consumers and deliberately resets progress.
Stream input is unbounded: ACK by both groups never trims entries and replay adds
entries. Follow-up is trimming only after both groups safely pass an entry,
including pending entries, with canonical Mongo re-emission retained as recovery.
Embedded histories remain a small-tracer design. Missing canonical evidence must
be restored before replay can succeed. Partial Cassandra rows after a later CQL
failure are idempotently retried; no ready marker is written for that failure.
Gate 5 move-marker/idempotency limitations remain unchanged. PR-B UI and manual
acceptance are not run; no MANUAL_PASS or production-readiness claim.

### PROPOSED ATOMIC COMMIT GROUPING

Control owns commits. This list supersedes the earlier grouping and names every
exact PR-A path. Intermediate commit groups have not been separately checked out
or tested. Exclude all owner WIP and unrelated task packets. Existing dependency
promotion/config edits belong to the prior PR-A implementation; this round did
not alter their contents.

1. **refactor(streams): extract shared bounded consumer core**
   - app/Processing/StreamConsumer.php
   - app/Interaction/InteractionStream.php
2. **feat(storylens): add canonical projections with conditional state and recovery**
   - composer.json
   - composer.lock
   - config/nosql.php
   - app/Content/ContentCatalog.php
   - app/StoryProcessing/CassandraClient.php
   - app/StoryProcessing/Neo4jClient.php
   - app/StoryProcessing/StoryGraph.php
   - app/StoryProcessing/StoryPipeline.php
   - app/StoryProcessing/StoryTimeline.php
   - app/StoryLens/StoryLens.php
   - app/Console/Commands/StoryLens.php
   - tests/Fixtures/storylens-rss-v1.json
   - tests/Feature/StoryCatalogTest.php
   - tests/Integration/StoryProjectionsTest.php
   - tests/Integration/CassandraSpikeTest.php
3. **docs(storylens): record recovery windows retention and round-one evidence**
   - docs/database/nosql-local.md
   - .agent/tasks/storylens-tracer/GATE-REPORT-6A.md


## Review-fix round 2

STATUS: **READY_FOR_REVIEW** (implementation evidence only). Branch remains
`storylens/tracer-pipeline`, HEAD `93daf7894f21ba6c749561fc1ba3e0a2aaefac67`.
All work remains uncommitted. No commits, stash, push, delegation, dependency,
Compose or .env edits in this round. Existing owner WIP and earlier PR-A dependency
and configuration changes were preserved. The prior round's P1/P2/P3/C1 resolution
records above are historical; this section addresses Terra pass 2's accepted P2/P3.

### Findings and implementation evidence

| Finding | Result | Change and exact source location |
|---|---|---|
| P2: surviving graph incorrectly fails during reemit | RESOLVED | `app/StoryProcessing/StoryGraph.php:25` branches in one Cypher statement: existing version >= requested event returns `covered=true` plus the held version, without changing graph properties/relationships. The actual projection branch still writes the full prefix and returns its count. `:46` accepts covered only when the held version is sufficient; `:49` retains the strict projected-count check for real writes. Canonical Article validation remains at `:14`. Mongo records only the consumed version, not the ahead graph version. |
| P2: analogous Cassandra replay | VERIFIED, no production change needed | Existing `app/StoryProcessing/StoryTimeline.php:22` reapplies the canonical prefix with unchanged `(story_id, occurred_at, event_id)` keys. `tests/Integration/StoryProjectionsTest.php:212` tests BOTH surviving stores: project v5, lose Redis DB15, reemit, consume v1 -> pending/1, consume v2-v5 -> ready/5, pending=0, DLQ empty, identical before/after facts. Graph additionally retains held version=5 and exactly five PART_OF relationships; timeline has exactly five rows. |
| P2: genuine incompleteness must still fail | VERIFIED | `tests/Integration/StoryProjectionsTest.php:278` supplies two explicit driver-result cases: actual partial write (1 of 2 Articles), and a claimed covered result holding v1 when v2 is requested. Both throw and leave Mongo failed with projected_version=0. Only this result-validation test substitutes the driver's result using the existing ClientInterface seam; healthy replay, graph topology, missing canonical evidence, rebuild, outage and DLQ cases use real stores. The previous artificial v99 test was replaced because an ahead graph is now intentionally covered. |
| P3: delayed success loses a newer failure | RESOLVED | `app/StoryProcessing/StoryPipeline.php:65` records `failed_version`, refuses older failure-marker replacement, and retains the existing projected-version guard. `:72` atomically advances successful progress while retaining failed/error/failed_version if the failure is newer; equal-or-newer success removes both marker fields and computes ready/pending from the current Mongo version. `:125` clears failed_version together with error during the explicit paused reemit reset. |
| P3: regression and recovery | VERIFIED | `tests/Integration/StoryProjectionsTest.php:243` runs for both sections: v2 fails at projected=0; v1 failure cannot replace its marker; v1 success advances projected=1 but retains failed/error/failed_version=2; duplicate v1 is ignored; v2 success removes both markers and reaches ready/2. Earlier inflight/current-version and late-result regressions remain green. |
| Terra note: Cassandra methods rely on first test's DDL | RESOLVED | `tests/Integration/CassandraSpikeTest.php:23` now creates the guarded vietfeed_test keyspace and story_timeline table in setUp before EVERY method, including the invalid-query method. Existing per-run partition cleanup and allowed-keyspace assertions remain. The reconnect method passes when invoked alone. No keyspace/table was dropped to manufacture a fresh environment; standalone execution was against the existing isolated test keyspace. |
| Documentation | UPDATED | `docs/database/nosql-local.md:352` specifies failed_version ordering, retained failed state and equal-or-newer clearing; `:445` documents surviving graph/Cassandra replay and pending-until-current behavior; `:472` distinguishes explicit coverage from actual-write completeness. |

### Red -> green

The accepted findings already identified the causes; broad speculative diagnosis
was unnecessary. Regression tests were added before production edits. No timings,
random delays or concurrent workers are used to force these interleavings.

| Command / stage | True exit | Counts and observed result |
|---|---:|---|
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php --filter 'surviving_projection\|older_success\|count_mismatch'` BEFORE production edits | 1 | 5 tests, 69 assertions, 3 failures, 2 passed. Graph surviving replay expected pending/1 but got failed/0/error. Both sections expected the v2 failure to survive v1 success, but got pending. Cassandra surviving replay and genuine count validation passed. |
| Same command immediately AFTER production fixes | 0 | 5 tests, 86 assertions, all passed. |
| Same command after expanding older-failure ordering and insufficient-covered-version coverage, and formatting | 0 | 6 tests, 103 assertions, all passed. These additional assertions are supplemental coverage; no separate pre-fix red is claimed for them. |

The spike setup correction has standalone green evidence below; no pre-fix fresh
keyspace failure was manufactured or claimed. Reemit reset remains an intentional
operator exception to normal monotonic progress and requires paused writers and
consumers. Independent same-section workers remain outside the tracer contract.
Readiness still requires successful progress at the current Mongo Story version.
A graph marker is trusted as the complete prefix written by the atomic projection;
manual corruption behind that marker is outside this recovery guarantee.

### Required verification (each command run separately)

No test command was piped through a formatter, grep or tee. Exit values below are
the command process exit codes returned by the execution tool. Mutating store
checks ran sequentially with SQLite :memory:, Mongo vietfeed_test, guarded live
Redis DB15, Cassandra vietfeed_test, and Neo4j cleanup restricted to tracked UUIDs.

| Command | Exit | Counts/result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` — run 1 | 0 | 8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` — run 2 | 0 | 8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` — run 3 | 0 | 8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` — run 1 | 0 | 22 tests, 373 assertions; local fixture convergence 0.332 s |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` — run 2 | 0 | 22 tests, 373 assertions; local fixture convergence 0.233 s |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` — run 3 | 0 | 22 tests, 373 assertions; local fixture convergence 0.231 s |
| `vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php` | 0 | 3 tests, 36 assertions |
| `vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php --filter=test_disconnect_and_reconnect_rereads_partition` | 0 | 1 test, 14 assertions |
| `composer run test` | 0 | 95 tests, 1203 assertions |
| `vendor/bin/pint --test` with all 14 PR-A PHP paths below | 0 | PASS |
| `git diff --check` | 0 | No whitespace errors |
| `./scripts/nosql-health.sh` | 0 | Mongo, Cassandra, Neo4j, Redis: 4/4 READY |

Exact style command (includes all PHP files from earlier PR-A rounds, not only
this round's four changed PHP files):

```bash
vendor/bin/pint --test app/Content/ContentCatalog.php app/Processing/StreamConsumer.php app/Interaction/InteractionStream.php app/StoryProcessing/CassandraClient.php app/StoryProcessing/Neo4jClient.php app/StoryProcessing/StoryGraph.php app/StoryProcessing/StoryPipeline.php app/StoryProcessing/StoryTimeline.php app/StoryLens/StoryLens.php app/Console/Commands/StoryLens.php tests/Feature/StoryCatalogTest.php tests/Integration/StoryProjectionsTest.php tests/Integration/CassandraSpikeTest.php config/nosql.php
```

### Store resource checks

Before/after `docker stats --no-stream --format '{{.Name}} {{.MemUsage}}'` and
`docker inspect --format '{{.Name}} restart={{.RestartCount}} OOM={{.State.OOMKilled}} started={{.State.StartedAt}}' vietfeed-mongo vietfeed-redis vietfeed-cassandra vietfeed-neo4j`
all returned exit 0. These are observations, not capacity/performance guarantees.

| Store | Before | After | Limit | Restarts before/after | OOM before/after |
|---|---:|---:|---:|---|---|
| Redis | 15.86 MiB | 15.88 MiB | 256 MiB | 0 / 0 | false / false |
| Cassandra | 1.439 GiB | 1.449 GiB | 1.5 GiB | 0 / 0 | false / false |
| Mongo | 167.5 MiB | 168 MiB | 768 MiB | 0 / 0 | false / false |
| Neo4j | 626.1 MiB | 797.2 MiB | 1 GiB | 0 / 0 | false / false |

All four StartedAt values were unchanged. Cassandra remained at
`2026-11-14T16:46:53.162357919Z`; no stop condition occurred.

### Updated acceptance table

PASS below means implementation verification. Independent review, Control/owner
acceptance and manual acceptance are separate; no MANUAL_PASS is claimed.

| # | Required outcome | Result / current evidence |
|---|---|---|
| 1 | Frozen real-RSS fixture | PASS — existing ten-item, two-Story fixture retained; provenance unchanged. No new network capture this round. |
| 2 | Content Catalog ingest | PASS — 8 Catalog tests / 193 assertions on each of three runs. |
| 3 | Versioned events/shared consumer | PASS — existing groups/history behavior retained; Composer suite green. |
| 4 | Mongo-derived replay | PASS — both deleted-store rebuild and surviving-store Redis-loss replay converge; no duplicate facts or DLQ in healthy replay. |
| 5 | Cassandra timeline | PASS — real projection tests and 3 spike tests; reconnect method alone passes; DDL runs before each method. |
| 6 | Neo4j topology | PASS — real topology retained; covered older prefixes succeed without projection writes; actual-write completeness enforced. |
| 7 | Idempotent/version-aware consumers | PASS — original stale/duplicate/ACK recovery plus surviving-store v1-v5 replay. |
| 8 | Mongo processing state | PASS — current-document readiness unchanged; older success/failure preserves newer failure; equal-version recovery clears it. |
| 9 | Canonical read before projection | PASS — existing pending/outage/legacy read regressions remain green. |
| 10 | Failure recovery without re-ingest | PASS — existing real fault/retry/DLQ/replay and both surviving-store repair cases pass. |
| 11 | Thin CLI | PASS — retained CLI integration coverage; domain changes require no CLI edits. |
| 12 | Convergence | PASS — 22 projection tests / 373 assertions on each of three runs; local fixture times recorded above. |
| 13 | Documentation | PASS — replay coverage and failed_version policy updated with exact code/test references above. |

### PROPOSED ATOMIC COMMIT GROUPING

Control owns commits. This supersedes earlier grouping, lists the exact PR-A paths,
and excludes owner WIP and unrelated task packets. These are proposed groups only;
intermediate commits were not created or separately tested. Dependency/configuration
paths below contain earlier authorized PR-A changes and were untouched this round.

1. **refactor(streams): extract shared bounded consumer core**
   - app/Processing/StreamConsumer.php
   - app/Interaction/InteractionStream.php
2. **feat(storylens): add canonical projections with ordered state and recovery**
   - composer.json
   - composer.lock
   - config/nosql.php
   - app/Content/ContentCatalog.php
   - app/StoryProcessing/CassandraClient.php
   - app/StoryProcessing/Neo4jClient.php
   - app/StoryProcessing/StoryGraph.php
   - app/StoryProcessing/StoryPipeline.php
   - app/StoryProcessing/StoryTimeline.php
   - app/StoryLens/StoryLens.php
   - app/Console/Commands/StoryLens.php
   - tests/Fixtures/storylens-rss-v1.json
   - tests/Feature/StoryCatalogTest.php
   - tests/Integration/StoryProjectionsTest.php
   - tests/Integration/CassandraSpikeTest.php
3. **docs(storylens): document replay coverage failure ordering and verification**
   - docs/database/nosql-local.md
   - .agent/tasks/storylens-tracer/GATE-REPORT-6A.md

## Review-fix round 3

STATUS: **READY_FOR_REVIEW** — implementation evidence only, awaiting independent
Review/Control acceptance. Branch `storylens/tracer-pipeline`, unchanged HEAD
`93daf7894f21ba6c749561fc1ba3e0a2aaefac67`; all work remains uncommitted.
This section supersedes round 2's graph-marker trust/rebuild limitation. Ordinary
stale/duplicate delivery still trusts a covered marker; explicit rebuild now resets
it before dispatch. No commits, stash, push, delegation, dependency, Compose or
.env edits were made in this round.

### Findings: change and evidence

| Finding | Result | Change and exact file:line |
|---|---|---|
| P2: partial graph loss hidden behind surviving v5 marker | RESOLVED | `app/StoryProcessing/StoryGraph.php:57` adds domain operation resetForRebuild; its single Cypher statement resets all selected existing Story markers to 0. `app/StoryProcessing/StoryPipeline.php:117` calls it for the entire selected set BEFORE any Mongo reset or publish. Ordinary covered shortcut remains at `app/StoryProcessing/StoryGraph.php:29`. |
| P2: fail closed if graph reset unavailable | RESOLVED | `app/StoryProcessing/StoryGraph.php:62` raises a clear, credential-safe error directing operators to ordinary retry/replay. `tests/Integration/StoryProjectionsTest.php:277` builds both projections for two Stories, connects a fresh graph client to unavailable localhost port 1, calls all-Story reemit, and asserts unchanged Mongo documents and Stream/group/DLQ lengths. |
| P2: repair partial loss in both real stores | VERIFIED | `tests/Integration/StoryProjectionsTest.php:242` runs separately for graph and timeline: build both at v5; delete exactly one PART_OF relationship or exactly one Cassandra primary-key row; verify graph marker remains 5; flush only Redis DB15; reemit and consume both sections. Full ordered Article/Source/Category relationship tuples and timeline rows equal their original five-fact sets; both Mongo sections ready/5, pending=0, DLQ empty, no duplicate facts. Existing surviving-store and full deleted-store rebuild tests remain green. |
| P3: count mismatch commits an advanced graph marker | RESOLVED | `app/StoryProcessing/StoryGraph.php:41` aggregates projected count before marker update; `:42` compares it with expected_count in Cypher and SETs version/title only on equality, in the same transaction. `:51` checks returned version as well as count before allowing Mongo success. Failed counts may leave idempotent MERGEd facts, never an advanced marker. |
| P3: real count mismatch regression | VERIFIED | `tests/Integration/StoryProjectionsTest.php:352` replaces the previous fabricated-result provider. A ClientInterface forwarding double sends the actual production Cypher to real Neo4j with expected_count=3 for two canonical Articles and returns the actual database result unchanged. This explicitly constructs the mismatch because clean canonical data normally matches; no graph corruption or mocked result is used. Two attempts both throw, retain graph version 0/Mongo projected_version 0 and failed state, return covered=false and projected=2. Restoring the correct parameter allows ready/2; subsequent direct stale graph projection returns covered=true, held version 2, projected=0. |
| P3: ingest loses error while retaining failed_version | RESOLVED | `app/Content/ContentCatalog.php:88` retains both failure fields by removing the error-only unset from the conditional attachment update. `tests/Integration/StoryProjectionsTest.php:302` tests both sections with failure v2, older failure v1, ingest v3 in the middle, then success v1. Ingest retains the exact error and failed_version=2; delayed v1 success remains failed/1 with the error. Success v2 clears both markers but remains pending/2 for current v3; success v3 reaches ready/3. Ingest continues to mark the new version pending, with failure evidence retained. Explicit reemit still clears both fields. |
| Documentation | UPDATED | `docs/database/nosql-local.md:351` documents retention through intervening ingest; `:443` specifies forced graph reset and fail-closed precondition, ordinary retry/replay while unavailable, Cassandra partial-row repair, and additive-only rebuild limitations. `:489` describes the transactional count guard. Rebuild does not remove extra stale facts or repair canonical Mongo loss. |

### Red -> green (tests written before each corresponding production fix)

Each command ran directly with no pipeline; exits are actual process exit codes.
Tests use deterministic operations, not concurrent workers or timing sleeps.

| Command / stage | Exit | Counts / observed result |
|---|---:|---|
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php --filter 'partial_loss\|fails_before_any_mongo'` BEFORE rebuild fix | 1 | 3 tests, 61 assertions, 2 failures, 1 passed. Graph remained missing its relationship; unavailable-graph reemit did not refuse. Cassandra partial-row repair already passed. |
| Same command AFTER rebuild fix | 0 | 3 tests, 64 assertions, all passed. |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php --filter count_mismatch` BEFORE transactional count guard | 1 | 1 test, 11 assertions, 1 failure. The original statement ignored the deliberately wrong expected_count and allowed success. |
| Same command AFTER transactional count guard | 0 | 1 test, 26 assertions. Both real mismatched attempts leave marker unadvanced and return uncovered; valid retry and ordinary covered stale delivery pass. |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php --filter older_success` BEFORE Catalog fix | 1 | 2 tests, 30 assertions, 2 failures. Intervening ingest removed error in both sections; expected retained message, got null. |
| Same command AFTER Catalog fix | 0 | 2 tests, 44 assertions, all passed. |

### Required verification — each command separately

All mutating store tests ran sequentially: SQLite :memory:, Mongo vietfeed_test,
live-guarded Redis DB15, Cassandra vietfeed_test, and Neo4j cleanup limited to
UUIDs created by each test. No dev-keyspace writes, global graph deletes, store
stops, schema drops or dependency changes were needed. Integration tests are an
explicit suite separate from Composer's Feature/Unit suite.

| Command | Exit | Counts/result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` — run 1 | 0 | 8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` — run 2 | 0 | 8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` — run 3 | 0 | 8 tests, 193 assertions |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` — run 1 | 0 | 24 tests, 445 assertions; fixture convergence 0.345 s |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` — run 2 | 0 | 24 tests, 445 assertions; fixture convergence 0.188 s |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` — run 3 | 0 | 24 tests, 445 assertions; fixture convergence 0.441 s |
| `composer run test` | 0 | 95 tests, 1203 assertions |
| `vendor/bin/phpunit tests/Integration/Neo4jSpikeTest.php` | 0 | 4 tests, 35 assertions |
| `vendor/bin/phpunit tests/Integration/CassandraSpikeTest.php` | 0 | 3 tests, 36 assertions |
| `vendor/bin/pint --test` with all 14 PR-A PHP paths below | 0 | PASS |
| `git diff --check` | 0 | No whitespace errors; rerun after report append also exit 0 |
| `./scripts/nosql-health.sh` | 0 | Mongo, Cassandra, Neo4j, Redis: 4/4 READY |

Exact style command, including every PHP file created/changed in the entire PR-A:

```bash
vendor/bin/pint --test app/Content/ContentCatalog.php app/Processing/StreamConsumer.php app/Interaction/InteractionStream.php app/StoryProcessing/CassandraClient.php app/StoryProcessing/Neo4jClient.php app/StoryProcessing/StoryGraph.php app/StoryProcessing/StoryPipeline.php app/StoryProcessing/StoryTimeline.php app/StoryLens/StoryLens.php app/Console/Commands/StoryLens.php tests/Feature/StoryCatalogTest.php tests/Integration/StoryProjectionsTest.php tests/Integration/CassandraSpikeTest.php config/nosql.php
```

Convergence times are local observations, not performance guarantees. Post-test
`docker stats --no-stream --format '{{.Name}} {{.MemUsage}}'` returned exit 0:
Redis 16.12 MiB / 256 MiB; Cassandra 1.441 GiB / 1.5 GiB; Mongo 167.6 MiB /
768 MiB; Neo4j 644.4 MiB / 1 GiB. Post-test docker inspect returned exit 0,
restart=0 and OOM=false for all four stores. StartedAt timestamps match round 2's
record: Mongo 2026-11-14T16:46:53.160790377Z; Redis
2026-11-14T16:46:53.161208044Z; Cassandra
2026-11-14T16:46:53.162357919Z; Neo4j
2026-11-14T16:46:53.161543335Z. No restart/OOM stop condition was observed.
A round-3 pre-test memory sample was not captured; no continuous or before/after
memory measurement is claimed.

Round-entry SHA256 comparison confirms all already-modified tracked paths other
than the owned nosql-local.md stayed byte-identical: three infrastructure STATUS
files, CLAUDE.md, InteractionStream.php, composer.json, composer.lock,
config/nosql.php, SourceSeeder.php and CassandraSpikeTest.php. .env and Compose
also remained byte-identical. Round 3 changed exactly four PHP paths
(ContentCatalog.php, StoryGraph.php, StoryPipeline.php, StoryProjectionsTest.php)
plus nosql-local.md and this appended report. Other owner/untracked work was not
edited. Existing dependency/configuration and spike changes belong to earlier
PR-A work and were preserved.

### Updated acceptance table

PASS means implementation verification only; independent Review, Control/owner
acceptance and manual acceptance remain separate. No MANUAL_PASS is claimed.

| # | Required outcome | Current result/evidence |
|---|---|---|
| 1 | Frozen real-RSS fixture | PASS — unchanged fixture/provenance; no new capture this round. |
| 2 | Content Catalog ingest | PASS — three runs, each 8 tests/193 assertions; failure evidence retained through ingest. |
| 3 | Versioned events/shared consumer | PASS — existing dispatch/groups/history retained; Composer suite green. |
| 4 | Mongo-derived replay/rebuild | PASS — surviving, fully lost and partially lost projections repaired; graph reset precedes all Mongo/dispatch writes. |
| 5 | Cassandra timeline | PASS — isolated real primary-key row deletion and full-set restoration without duplicates; spike 3/36. |
| 6 | Neo4j topology | PASS — missing relationship restored behind v5; count mismatch cannot advance version; ordinary covered delivery retained; spike 4/35. |
| 7 | Idempotent/version-aware consumers | PASS — stale/duplicate/ACK regressions retained; explicit rebuild forces prefix MERGEs. |
| 8 | Mongo processing state | PASS — failure v2 -> ingest v3 -> success v1 retains error/failed_version; v2 clears failure but remains pending; v3 ready. |
| 9 | Canonical read before projection | PASS — existing pending/outage/legacy read tests remain green. |
| 10 | Failure recovery without re-ingest | PASS — existing DLQ/replay tests plus fail-closed rebuild outage test; retry/replay remains the down-store path. |
| 11 | Thin CLI | PASS — existing inspect/replay/reemit coverage remains green; no CLI edits required. |
| 12 | Convergence | PASS — three Integration runs, each 24 tests/445 assertions; local fixture times above. |
| 13 | Documentation | PASS — forced rebuild, fail-closed precondition, transactional count guard and additive-only limitations documented. |

Remaining operational limits: pause writers/consumers before rebuild. Graph reset,
Mongo state changes and Redis publishing are not an atomic multi-store transaction;
a later failure requires another paused rebuild. Rebuild adds canonical missing
facts but does not remove stale extra facts or repair missing canonical evidence.
Existing single-writer, retention, embedded-history and Gate 5 limitations remain.
PR-B UI, independent Review and owner/manual acceptance were not performed here.

### PROPOSED ATOMIC COMMIT GROUPING

Control owns commits. This final list supersedes earlier groupings and contains
exact PR-A paths only. No commits were made or intermediate groups independently
checked out/tested. Existing dependency/config paths contain earlier PR-A changes;
round 3 did not modify them. Exclude owner WIP and unrelated task packets.

1. **refactor(streams): extract shared bounded consumer core**
   - app/Processing/StreamConsumer.php
   - app/Interaction/InteractionStream.php
2. **feat(storylens): add canonical projections with forced rebuild and ordered state**
   - composer.json
   - composer.lock
   - config/nosql.php
   - app/Content/ContentCatalog.php
   - app/StoryProcessing/CassandraClient.php
   - app/StoryProcessing/Neo4jClient.php
   - app/StoryProcessing/StoryGraph.php
   - app/StoryProcessing/StoryPipeline.php
   - app/StoryProcessing/StoryTimeline.php
   - app/StoryLens/StoryLens.php
   - app/Console/Commands/StoryLens.php
   - tests/Fixtures/storylens-rss-v1.json
   - tests/Feature/StoryCatalogTest.php
   - tests/Integration/StoryProjectionsTest.php
   - tests/Integration/CassandraSpikeTest.php
3. **docs(storylens): document forced rebuild and final review-fix evidence**
   - docs/database/nosql-local.md
   - .agent/tasks/storylens-tracer/GATE-REPORT-6A.md

## Independent review (gpt-6.1-terra, read-only, medium)

Every pass was pinned to a sha256 manifest of the reviewed files.

| Pass | Verdict | Findings |
|---|---|---|
| 1 | FAIL | P1 stale-readiness race (consumer writes ready from a stale Story; ingester clobbers the processing snapshot); P2 graph accepts fewer Articles than the event prefix; P2 untrimmed Story stream; P3 Article/Story/publish not atomic |
| 2 | FAIL | Pass-1 items RESOLVED. New P2: reemit over a surviving newer graph reported a false failure; P3 older success clears a newer failure marker |
| 3 | FAIL | Pass-2 items RESOLVED. P2: rebuild trusted the graph version marker, so partial graph loss was not repaired; P3 version advanced before the count check; P3 ingest dropped the failure message |
| 4 | **PASS_WITH_NOTES** | All RESOLVED. Remaining notes are operational/theoretical under the single-writer, paused-rebuild precondition |

Control findings and rulings:
- C1 (control, round 1): `StoryLens::read` and the pipeline failed on legacy Gate 3 Story documents — fixed.
- Untrimmed Story stream: accepted as a documented tracer limitation with a follow-up (trim once both groups have passed an entry).
- Article -> Story -> publish stays recoverable rather than atomic; both interruption windows are tested and the repair command is documented.
- The "covered" shortcut stays for ordinary stale/duplicate redelivery; `reemit` is the rebuild path and forces re-projection, fail-closed if the graph cannot be reset.
- `tests/Integration/CassandraSpikeTest.php` was changed by the worker without assignment (keyspace `vietfeed_spike` -> `vietfeed_test`, per-test cleanup, bootstrap in `setUp`). Control accepted it because it serves the isolation requirement; Terra reviewed it.

## Control final gate (Fable 5.2, 2026-11-15)

Run after the last code change with no other worker active; each command its own invocation; exit code of the command itself.
File set verified against the pass-4 manifest after the gate.

| Command | Exit | Result |
|---|---|---|
| `composer check-platform-reqs` | 0 | pass |
| `./scripts/nosql-health.sh` (before / after) | 0 / 0 | 4/4 READY |
| `vendor/bin/phpunit tests/Feature/StoryCatalogTest.php` x3 | 0, 0, 0 | 8 tests / 193 assertions each |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` x3 | 0, 0, 0 | 24 / 445 each |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` | 0 | 29 / 495 (unchanged after the consumer extraction) |
| `RedisQueueTest`, `RedisSessionTest` | 0, 0 | 3/42, 4/47 |
| `composer run test` | 0 | 95 / 1203 |
| Mongo / Neo4j / Cassandra integration | 0, 0, 0 | 3/19, 4/35, 3/36 |
| `vendor/bin/pint --test` on all PR-A PHP files | 0 | passed |
| `git diff --check`, `composer validate --no-check-publish` | 0, 0 | clean |

Memory after gate: redis 16.3MiB/256MiB, cassandra 1.442GiB/1.5GiB, mongo 167.6MiB/768MiB, neo4j 645.1MiB/1GiB; no OOM, no restarts.

**Verdict: local tests PASS, independent review PASS_WITH_NOTES -> overall PASS_WITH_NOTES.**

Control notes:
- `StoryProjectionsTest` is container-backed and lives in `tests/Integration/`; it is NOT part of `composer run test`.
- Only the final tree was gate-tested, not each intermediate commit. Terra never ran PHPUnit; runtime evidence is the worker's and control's.
- Fixture provenance (real VnExpress / Tuoi Tre RSS captured 2026-11-14) is worker-reported; control did not re-fetch the feeds.
- Rebuild requires paused writers/consumers and is additive only; after any interruption repeat `reemit` before trusting derived reads.
- No HTTP endpoint or UI yet: the canonical-first read is proven at the domain and CLI level. PR-B adds the Blade page.
- Source/Category graph keys are interim UUIDv5 values until those models leave SQL.
