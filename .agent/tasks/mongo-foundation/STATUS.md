# STATUS — mongo-foundation

- Branch: `mongo/package-5`
- Base commit: `8ccb9c6` (main, merge of PR #4)
- Classification: Medium (touches auth + prod model paths; no billing/API/session-driver changes)
- Control session: pi-control
- Grill: SKIPPED by decision (spec + Gate 3 clear; frontier budget reserved)

## Scouts

- Repo scout: done — Breeze+Google mapped, 17 withCount/5 raw/1 join inventoried, zero UUID, Dashboard:63-66 pilot adopted
- Test scout: done — 7 Auth files mapped; tension resolved: mongo test DB for DB-backed cases, sqlite for render-only
- Risk scout: done — session/reset stay, Google upsert design adopted, pivot→embed, billing untouched

## Tickets

- T1 (package + config + indexes + test wiring): GitHub #5 — PASS, checkpoint 2026-09-22 (see T1 result below)
- T2 (full auth surface on Mongo): GitHub #6 — not started — blocked by #5
- T3 (Article/Story CRUD + UUIDv7 + Dashboard pilot): GitHub #7 — not started — blocked by #5, parallelizable with #6

## Files changed (T1)

- `composer.json` / `composer.lock` — `mongodb/laravel-mongodb ^5.7` (prod dep; resolved 5.8.2)
- `config/database.php` — `mongodb` connection from MONGO_* env (+ MONGO_DSN override), database from MONGO_DB
- `phpunit.xml` — `MONGO_DB=vietfeed_test` under test (mongo test double DB)
- `tests/Integration/MongoConnectionTest.php` — round-trip + unique-index enforcement proof
- `tests/Integration/MongoAuthDoubleTest.php` — login attempt via real guard/provider on double
- `tests/Doubles/MongoTestUser.php` — test-only User on mongodb connection (prod model untouched)

## Decisions (T1 session)

- ext-mongodb is Herd-pinned at 2.3.3 while the L13-compatible stack wants ^2.4:
  installed with `--ignore-platform-req=ext-mongodb`, validated at runtime by the
  spike tests (all exercised paths green). CI/prod needs ext-mongodb ^2.4; revisit if
  a path hits a 2.4-only API.
- Stock Eloquent models build SQL builders even on the mongodb connection → double
  extends `MongoDB\Laravel\Eloquent\Model` (package's documented User recipe).
- `users.google_id` index is sparse+unique (nullable field); `users.email` plain unique.
- Durable index declarations ride with T2's User migration; T1 proves enforcement.
- Full Breeze register-via-HTTP on mongo needs T2's prod model move — T1 proves the
  login half (attempt + authenticated + doc in mongo) through the real provider stack.

## Decisions

- Grill skipped (2026-09-22): Gate 3 checklist is executable as-is.
- Session/cache/queue drivers stay untouched (Gate 5 owns that move).
- Scout-driven scope (2026-09-22): Google callback rewritten with unique-index + upsert/retry
  (no Mongo transactions); password broker stays SQL (cross-store, documented);
  category_user → embedded favorite_category_ids + onboarding sync rewrite;
  first join replacement = DashboardController:63-66; test double = compose-Mongo vietfeed_test.
- T2/T3 parallelizable after T1: T2 owns User + Auth/* + onboarding + user validators;
  T3 owns Article (+ minimal Story) + Dashboard:63-66. No overlapping files.

## Acceptance criteria status

- Gate 3 bullet 1 (package + config): T1 PASS — see T1 result below
- Gate 3 bullet 2 (register/login on Mongo): OPEN (T2; login half proven on double)
- Gate 3 bullet 3 (CRUD + UUIDv7): OPEN
- Gate 3 bullet 4 (one join replacement): OPEN
- Gate 3 bullet 5 (boot without SQLite/MySQL): OPEN

## Test results

### T1 (2026-09-22, exact)

- `composer require "mongodb/laravel-mongodb:^5.7" --ignore-platform-req=ext-mongodb` → 5.8.2 + mongodb/mongodb 2.4.x on ext 2.3.3
- Tinker round-trip on `vietfeed_test`: insert + where-first → doc with ObjectId, OK
- Tinker indexes on dev `vietfeed.users`: `email_1` unique, `google_id_1` unique+sparse
- Tinker duplicate email → `BulkWriteException` code 11000; two null-google_id docs coexist; collection cleaned
- `vendor/bin/phpunit tests/Integration/MongoConnectionTest.php` → 2/2 PASS (round-trip, 11000 enforcement)
- `vendor/bin/phpunit tests/Integration/MongoAuthDoubleTest.php` → 1/1 PASS (attempt true, authenticated, doc in mongo `users`)
- `vendor/bin/pint --test` on 4 touched PHP files → clean (auto-fixed ordered_imports/strict_types in connection test)
- `composer run test` → 44/44 PASS, 202 assertions (Integration dirs outside Unit/Feature suites)
- Render-only Auth tests untouched (stay sqlite per scout split); stray `mongo_test_users` probe collection dropped
