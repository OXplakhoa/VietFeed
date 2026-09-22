# STATUS — mongo-foundation

- Branch: `mongo/auth-6`
- Base commit: `2a29c39` (main, merge of PR #8)
- Classification: Medium (touches auth + prod model paths; no billing/API/session-driver changes)
- Control session: pi-control
- Grill: SKIPPED by decision (spec + Gate 3 clear; frontier budget reserved)

## Scouts

- Repo scout: done — Breeze+Google mapped, 17 withCount/5 raw/1 join inventoried, zero UUID, Dashboard:63-66 pilot adopted
- Test scout: done — 7 Auth files mapped; tension resolved: mongo test DB for DB-backed cases, sqlite for render-only
- Risk scout: done — session/reset stay, Google upsert design adopted, pivot→embed, billing untouched

## Tickets

- T1 (package + config + indexes + test wiring): GitHub #5 — MERGED via PR #8 (08a834b, 2026-09-22). Gate 3.1 DONE.
- T2 (full auth surface on Mongo): GitHub #6 — PASS, checkpoint 2026-09-22 (see T2 result below)
- T3 (Article/Story CRUD + UUIDv7 + Dashboard pilot): GitHub #7 — UNBLOCKED, parallelizable with T2
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

## Files changed (T2)

- `app/Models/User.php` — mongo document (package Auth base, `mongodb` conn, `_id` key,
  role default, `favorite_category_ids` fillable/cast, `newRelatedInstance` SQL pin)
- `app/Http/Controllers/Auth/GoogleAuthController.php` — upsert/retry, no transaction/lock
- `app/Http/Controllers/Auth/RegisteredUserController.php` — `unique:mongodb.users`
- `app/Http/Requests/ProfileUpdateRequest.php` — `Rule::unique('mongodb.users')->ignore(key, '_id')`
- `app/Http/Controllers/Admin/UserController.php` — `unique:mongodb.users,…,​_id`
- `app/Http/Controllers/OnboardingController.php` — embedded ids read/write
- `app/Http/Controllers/ProfileController.php` — preferences write embedded (spillover, 1 line)
- `app/Http/Controllers/HomeController.php` + `resources/views/home/index.blade.php` —
  favorites read from embedded ids (spillover; Home/Profile are not T3 files)
- `database/migrations/2026_09_22_000001_create_mongodb_user_indexes.php` — durable unique indexes
- 6 migrations: `*_user_id`/`reporter_id`/`admin_id` → string+index, users-FKs dropped
  (category_user pivot + sessions table kept: Gate 5 / later slice)
- `tests/TestCase.php` — mongo `users` wipe per test (RefreshDatabase is SQL-only)
- `tests/Feature/Auth/GoogleAuthenticationTest.php` — assertDatabaseHas on `mongodb`
- `tests/Feature/OnboardingInterestsTest.php` — new: interests round-trip
- `docs/database/nosql-local.md` — Gate 3 notes (SQL broker, isolation, string ids)

## Decisions (T2 session)

- Cross-store relations: stock `newRelatedInstance` inherits parent mongo conn (root cause
  of all middleware 500s) → pinned on User; single-point fix for every hasMany path.
- Race retry: `catch (BulkWriteException 11000)` → re-read winner. No deterministic
  single-process race test exists (find ⊇ unique fields ⇒ only true concurrency hits it);
  enforcement covered by 11000 test; behavior by new/existing-user characterization tests.
- `unique:Model::class` resolves against the DEFAULT (SQL) connection, not the model's —
  all three validator sites now name `mongodb.users` explicitly (+ `_id` ignore key).
- Spillover (flagged, not T3): Home/Profile favorites reads+writes follow the embed so the
  retired pivot can't split-brain; SQL↔SQL FKs (article/comment/pivot) untouched.

## Decisions

## Review T1 (pi-review, 2026-09-22) — verdict PASS_WITH_NOTES

- REVIEW.md T1 section (secret-clean). Standards PASS, all #5 criteria green on re-run.
- N1 (doc fix, NOT manifest): ext-mongodb ^2.4 requirement lives only in STATUS prose;
  pinning ext in manifest would brick the Herd-pinned host. Fix = document requirement
  in durable doc (not composer.json). No other notes.

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
- Gate 3 bullet 2 (register/login on Mongo): T2 PASS — see T2 result below
- Gate 3 bullet 3 (CRUD + UUIDv7): OPEN
- Gate 3 bullet 4 (one join replacement): OPEN
- Gate 3 bullet 5 (boot without SQLite/MySQL): OPEN

## Test results

### T2 (2026-09-22, exact)

- `php artisan test tests/Feature/Auth/RegistrationTest.php` → 2/2 (register creates mongo doc)
- `php artisan test --filter=Auth` → 21/21 (7 files: register/login/logout/verify/reset/update/confirm/Google)
- `php artisan test tests/Feature/Auth/GoogleAuthenticationTest.php` → 2/2 (new + existing user via rewritten callback)
- `php artisan test tests/Feature/OnboardingInterestsTest.php` → 1/1 (embed + preselect round-trip)
- `composer run test` → 45/45 PASS (44 + onboarding; Integration excluded by suite config)
- `vendor/bin/pint --test` on all 19 touched PHP files → clean (other Pint hits are pre-existing debt)
- Password reset e2e green with SQL broker (PasswordResetTest); billing untouched (ProSubscriptionTest green)
- Role semantics: model `$attributes` default `user` replaces SQL default; `isAdmin/isUser` unchanged

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
