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
