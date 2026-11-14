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

This is a thin infrastructure proof, not the StoryLens product pipeline. The
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
| `interactions:trending:v1` | Sorted Set containing scores and idempotency/version markers |
| `interactions:trending:v1:rebuild:<random>` | Temporary rebuild projection, renamed on success |

`App\Interaction\InteractionStream` is the processing seam; `Trending` is the
projection seam. Producers supply immutable envelopes with UUIDv7 `event_id`,
`type` (`view` / `boost`), UUIDv7 `aggregate_id`, positive integer
`aggregate_version`, RFC3339 `occurred_at`, and `article_id`. For this proof each
aggregate is an independent interaction, not an Article-wide sequence. Duplicate
event IDs and versions less than or equal to the latest interaction version are ignored.
Both UUID fields are canonicalized to lowercase before dedupe/version comparisons.
A view adds **1**, a boost adds **5**. Unboost, decay, windows and live product
producers are later work. Ties use Redis reverse lexicographic member ordering.

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
recording, ACK/move and rebuild failures propagate to a non-zero CLI exit. This
is not a guarantee for every Redis call: group creation, publish, terminal-error HGET, inspection and
top reads are not all wrapped with command-specific result validation.
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
php artisan interactions top --limit=10
# Pause all interaction consumers/producers before replacing the projection:
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
temporary key to inspect/delete individually. There is no fixture-to-product
producer connection. The Cassandra activity-by-article/day replay adapter is
explicitly deferred to Gate 6; its library remains require-dev.

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
