# Gate 8 — API tokens

## Implementation

### Plan (before implementation)

- Module seam: `App\ApiTokens\ApiTokenService::{issue,authenticate,refresh,revokeFamily,revokeAllForUser}`. HTTP seam: stateless `/api/v1`, isolated from existing web routes.
- Redis: `nosql`, `api-token:access:<sha256>`, `refresh:<sha256>`, `family:<random id>`, `user:<id>` sorted family index. Only hashes cross Redis; lookup never scans. Lua owns atomic issue/rotation/reuse/revocation and prunes expired family-index members.
- TTL: positive integer config sourced from AUTH_ACCESS_TOKEN_TTL_SECONDS / AUTH_REFRESH_TOKEN_TTL_SECONDS; token records retain a bounded 60-second expiry diagnostic grace. Redis server time determines logical expiry. Rotated refresh markers retain the original lifetime plus grace. Existing access tokens retain their own TTL after rotation; family revocation invalidates all generations.
- Endpoints: POST auth/login, auth/refresh, auth/logout; GET auth/me, stories/{story}; POST boosts/toggle reuses existing BoostController. Login/me/Story allow unverified email; interaction requires verified email and current sanction permission.
- Errors: `{error:{code:...}}`; generic invalid_credentials, invalid/expired access/refresh token, token_family_revoked, refresh_token_reused, account_unavailable, email_unverified, interaction_forbidden, validation_error, rate_limited, not_found, method_not_allowed, service_unavailable. Token responses use no-store.
- Hooks: User password update/deleting model events cover existing password update/reset and both deletion paths; permanent-ban Sanction model event covers existing sanction paths. No new admin revoke feature.
- TDD slices: module issue/auth; HTTP login/isolation; atomic rotation/replay; both expiry orders; logout/account revocation; Mongo authority; validation/errors/throttle; existing Story/boost reuse; raw token absence, actual Redis TTL/index bounds, false Redis result/failure handling, concurrent refresh.
- Safety: no cached config, Mongo vietfeed_test, SQLite :memory:, live Redis DB15 guard before mutation; sequential tests. Preserve initial owner files by SHA-256 baseline. No dependency, compose, active .env, commits, delegation or server changes.
- Verification: each new test file three consecutive invocations, requested regression/suite/platform/Pint/diff/health invocations with true exit codes. Curl walkthrough mapped to passing HTTP tests; commands themselves not executed.

### Initial environment

Branch `auth/api-tokens`; HEAD `3149cf959cd62352e85d7ce990858723b72b3b66`. No bootstrap/cache/config.php. Owner WIP fingerprinted outside repository before implementation.

### Implementation result

STATUS: **READY_FOR_REVIEW**. Implementation evidence only; independent Review,
Control acceptance and owner manual acceptance are not claimed. Branch and HEAD
remain unchanged. No commit, stash, push, PR, delegation, dependency installation,
container stop/restart, server start, active `.env` or compose edit was performed.

### Decisions and why

1. **One security module, narrow HTTP adapter.** ApiTokenService owns issue,
   authenticate, refresh, revokeFamily and revokeAllForUser. Controllers/middleware
   and model hooks call it. Only its checked Lua operation accesses token keys.
   Story read reuses StoryLens::readForPage; POST boosts/toggle delegates to the
   existing BoostController, with bearer identity supplied on the Request rather
   than populating the web guard/session. No auth controller was restructured.
2. **Hashes, entropy and key layout.** AT/RT are independent 32-byte random values,
   encoded base64url without padding. SHA-256 provides direct lookup for these
   256-bit secrets; password-style slow hashing is unnecessary for random tokens.
   The independently random family ID is an internal identifier, never a bearer
   credential. Logical keys (connection prefix added once) are:
   - `api-token:access:<sha256>` / `refresh:<sha256>`: JSON uid, family and absolute
     expiry; rotated refresh adds used=true.
   - `api-token:family:<independent-id>`: User ID and physical TTL, the live
     authorization authority for all token generations in that family.
   - `api-token:user:<user-id>`: sorted family index, scored by physical expiry.
   No roles, permissions, passwords or raw AT/RT enter this state.
3. **Seconds and expiry diagnostics.** Environment values are read only via
   nosql.api_tokens config. Defaults AT=900, RT=2592000; reject non-integers,
   booleans, floats, zero/negative and values above 31536000 seconds. The one-year
   ceiling bounds retention and avoids accidental extreme TTLs. Redis TIME in
   integer seconds governs logical expiry. Token metadata has physical TTL
   `configured lifetime + 60 seconds`; the 60-second grace permits expired-vs-
   unknown errors without authorizing any grace-period request. Once metadata is
   gone the same token is invalid. Lookup precedence is unknown, expired, revoked
   family, then rotated-refresh reuse. Thus expired revoked tokens report expired.
4. **Rotation/reuse.** The actual rotation rechecks the old refresh state and
   consumes it atomically in Lua. A preliminary identity lookup allows checking
   the current Mongo User before creating a replacement. Two concurrent HTTP
   requests use independent PHP processes/Redis connections; exactly one succeeds,
   the duplicate detects reuse and revokes even the winning pair. Clients must
   serialize refresh and reauthenticate after ambiguous retries. The old access
   token retains its own lifetime; this avoids interrupting legitimate parallel
   resource requests. Family revocation still stops every generation immediately.
   KEEPTTL preserves the rotated refresh marker for its original lifetime plus
   diagnostic grace, even if configuration subsequently shortens replacement TTL.
5. **Bounded account index, no scan in requests.** Issue and rotation prune expired
   index members and expire the index at its largest score. Family/index lifetime
   never shrinks below an older access token's horizon. Logout removes one family;
   account revoke walks only that user's family sorted set, deletes family
   authorities and the index atomically. Old token metadata ages out naturally.
   There is no KEYS/SCAN in the request implementation; those commands appear only
   in test/operator inspection. Retention is bounded, with no separate cap on the
   number of simultaneously active families.
6. **Revocation hooks.** User updating(password dirty) and deleting hooks execute
   before persistence, covering existing profile password update, password reset,
   self-delete and admin delete without restructuring those actions. An active
   permanent-ban Sanction saving hook covers existing sanction paths. Redis errors
   abort these writes rather than reporting successful revocation. One necessary
   existing-flow repair changes the admin sanction user existence validation from
   SQL users to MongoDB users. The existing SQL sanctions/boost/reset-token stores
   are retained. There is **no standalone admin account-revoke action** to wire;
   no such feature was invented. Temporary bans also fail current API identity
   checks; unlike permanent bans, they do not destroy every family on save.
7. **Identity and verification policy.** Every bearer request and refresh loads
   the User again from Mongo and checks current sanctions. Deleted/banned users
   fail even when model hooks were bypassed. Unverified users may log in, refresh,
   read me and read Stories; writing a boost requires current verified email and
   canBoost(), so the API does not grant an unverified write capability. No new
   RBAC model or permission snapshots are introduced.
8. **Login and generic errors.** Five attempts per IP per minute through the
   existing Laravel RateLimiter, including successes and validation failures.
   Password processing always creates a dummy hash and checks one hash for both
   unknown-user and wrong-password branches; this intentionally spends the same
   extra hash work on each branch. Median end-to-end timing is tested at bcrypt
   cost 8 (three samples each, >20ms and a 0.6..1.7 relative timing band). This is
   a bounded regression check, not a constant-time/network side-channel proof.
   Issuing responses and API errors use no-store. API exceptions render only a
   stable code; unexpected exceptions log only their class, without request or
   exception-message context. SensitiveParameter protects raw service arguments;
   Redis errors are replaced without retaining their prior exception.
9. **Isolation and deterministic tests.** The base TestCase rejects cached config,
   requires Mongo vietfeed_test and SQLite :memory:, and pins all Redis connections
   to DB15. The new file invokes the live guard before mutations/flushes; the
   module additionally refuses token operations outside live DB15 during tests.
   Concurrent HTTP workers assert the same store configuration before migrations
   or token operations, use separate SQLite :memory: schemas, and receive tokens
   only via pipes. No server is launched. All mutating test invocations were
   sequential; only the required two refresh requests contend within one test.
   Expiry tests use 1/4-second TTLs and explicit 2-second waits (eight seconds total
   across both orders); the longer TTL gives margin against subsecond issuance.
   The marker-retention test adds one 2-second wait. No PHP clock fake is used.
10. **Web boundary.** API routes use the separate api middleware group, with no
    session, cookies or CSRF; bearer identity never falls back to actingAs/web.
    API tests also configure the real Redis web-session driver and prove it never
    starts or writes session keys. Existing web routes /api/live-search,
    /api/articles and /api/ticker retain the web group. Legacy search/articles HTTP
    calls pass; ticker routing is asserted without making an external price call.

### Error contract

All API failures use `{ "error": { "code": "..." } }`, with no details, hashes,
keys, validation values, stack traces or credential hints.

| HTTP | Machine code | Meaning |
|---|---|---|
| 401 | invalid_credentials | Unknown email, wrong password or banned login; identical body |
| 401 | invalid_access_token | Missing, unknown, modified, wrong-kind or aged-out access metadata |
| 401 | invalid_refresh_token | Unknown, modified, wrong-kind or aged-out refresh metadata |
| 401 | access_token_expired | Logical access expiry, inside bounded diagnostic horizon |
| 401 | refresh_token_expired | Logical refresh expiry, inside bounded diagnostic horizon |
| 401 | token_family_revoked | Live token metadata but no active family authority |
| 401 | refresh_token_reused | Live rotated refresh replay; family revoked by this operation |
| 401 | account_unavailable | Current User deleted or banned, even with surviving Redis authority |
| 403 | email_unverified | Boost requires verified email |
| 403 | interaction_forbidden | Current canBoost policy denies interaction |
| 404 | not_found | Unknown API route/resource or malformed Story UUID |
| 405 | method_not_allowed | Known API path, wrong HTTP verb |
| 422 | validation_error | Required/type/length/existence validation, no submitted values echoed |
| 429 | rate_limited | Five login attempts reached within the minute |
| 503 | service_unavailable | Invalid TTL configuration, checked Redis failure or unexpected failure |

### Files created/changed

Created:

- app/ApiTokens/ApiTokenService.php
- app/ApiTokens/TokenException.php
- app/ApiTokens/tokens.lua
- app/Http/Controllers/Api/TokenController.php
- app/Http/Middleware/ApiBearer.php
- routes/api.php
- tests/Feature/ApiTokensTest.php
- docs/demo/api-token-demo.md
- .agent/tasks/api-tokens/GATE-REPORT-8.md

Changed:

- .env.example
- config/nosql.php
- bootstrap/app.php
- app/Models/User.php
- app/Models/Sanction.php
- app/Http/Controllers/Admin/SanctionController.php
- docs/database/nosql-local.md

TASK.md was not rewritten. All **48 initial owner files** (modified and untracked,
excluding this api-tokens packet) still match their initial SHA-256 fingerprints.
That baseline covers the listed protected packets, CLAUDE.md, SourceSeeder and
untracked migration/.pi/docs/agents material. No owner file was staged or edited.

### Acceptance — all 14 ticket outcomes

Evidence below is implementation evidence from tests/inspection, not independent
Review or owner MANUAL_PASS. Test names refer to tests/Feature/ApiTokensTest.php.

| # | Required outcome | Status | Evidence |
|---|---|---|---|
| 1 | Module owns issue/auth/refresh/family/account revoke, checked nosql results | PASS | Module interface test; false-EVAL test exercises all five operations; only service/Lua touches token keys |
| 2 | Opaque >=256-bit tokens; hashes only; no raw keys/values/logs | PASS | random_bytes(32); direct SHA-256 lookups; raw-token test scans every DB15 key and dumped value plus captured flow logs after issue/rotation/replay |
| 3 | Environment-configured second TTLs, validation and safe examples | PASS | Actual physical TTL assertions (17+60 and 31+60), independent logical expiry, 16 invalid-config datasets across both kinds; only .env.example changed |
| 4 | Six minimum stateless JSON endpoints | PASS | HTTP login/refresh/logout/me, Story read and existing boost toggle tests; no cookies/session/CSRF or web fallback |
| 5 | Mongo login, generic errors, throttle, banned rejection | PASS | Login failure/throttle test; timing test; permanent-ban HTTP test; unverified-policy test |
| 6 | Atomic one-use refresh; documented old access policy | PASS | Rotation test and two independent concurrent HTTP processes yield exactly [200,401]; old AT works until expiry/family revoke |
| 7 | Refresh replay revokes all generations, original marker horizon | PASS | Three-generation replay test invalidates all AT/RT; shorter replacement TTL test proves retained original marker |
| 8 | Independent expiry, refresh shorter than access, unknown distinction | PASS | Both 1/4-second expiry-order datasets; tampered/cross-kind tests; metadata horizon/TTL and removal check |
| 9 | Logout and all existing account-wide revocation actions | PASS | Family-only logout; two-family password update/reset, self-delete, admin delete and permanent-ban HTTP tests. No standalone admin account revoke exists |
| 10 | Current Mongo identity on every authenticated request | PASS | Module observes updated User name; HTTP bypasses deletion/ban hooks and still denies AT/RT; no Redis role/permission data |
| 11 | Stable generic JSON errors including unknown routes and validation | PASS | Exact JSON tests for invalid credentials/tokens, 422, 404, 405 and 503; errors contain no submitted values or driver diagnostics |
| 12 | Existing web auth/session/routes preserved | PASS | Auth 20/20, RedisSession 4/4, RedisStreams 29/29, StoryPage 13/13, full suite 184/184; legacy web route membership asserted |
| 13 | Feature tests included in composer run test, isolated real stores | PASS | 42 new test cases/750 assertions, three final consecutive runs; full suite includes them; explicit DB15/Mongo/SQLite guards and independent worker assertions |
| 14 | Curl demo, Redis layout/native inspection, gate report | PASS | docs/demo/api-token-demo.md maps every HTTP step to passing tests; docs/database/nosql-local.md records layout/TTL/native commands; Bash syntax checked. Curl itself NOT RUN |

### Verification — separate invocations, true exit codes

Each row below was executed as its own command invocation, without tail/tee or
shell exit masking. All mutations were sequential.

| Command | Exit | Evidence |
|---|---:|---|
| vendor/bin/phpunit tests/Feature/ApiTokensTest.php — final run 1 | 0 | 42 tests, 750 assertions, 12.968s |
| vendor/bin/phpunit tests/Feature/ApiTokensTest.php — final run 2 | 0 | 42 tests, 750 assertions, 12.970s |
| vendor/bin/phpunit tests/Feature/ApiTokensTest.php — final run 3 | 0 | 42 tests, 750 assertions, 12.878s |
| vendor/bin/phpunit tests/Feature/Auth | 0 | 20 tests, 131 assertions |
| vendor/bin/phpunit tests/Feature/RedisSessionTest.php | 0 | 4 tests, 47 assertions |
| vendor/bin/phpunit tests/Feature/RedisStreamsTest.php | 0 | 29 tests, 495 assertions |
| vendor/bin/phpunit tests/Feature/StoryPageTest.php | 0 | 13 tests, 343 assertions |
| composer run test | 0 | 184 tests, 2772 assertions, 17.897s; config clear then artisan test |
| composer check-platform-reqs | 0 | All 24 listed requirements successful; PHP 8.4.27, ext-redis 6.3.0, ext-mongodb 2.5.2 |
| vendor/bin/pint --test (all 11 PHP paths below) | 0 | Passed |
| git diff --check | 0 | No whitespace errors |
| ./scripts/nosql-health.sh | 0 | Mongo, Cassandra, Neo4j, Redis: 4/4 READY |
| Bash syntax check of all demo bash fences via bash -n stdin | 0 | Parser only; commands not executed |
| Owner SHA-256 comparison / cached-config assertion | 0 | 48/48 unchanged; bootstrap/cache/config.php absent |
| docker inspect restart/OOM/start timestamps, before and after | 0 | All restart=0, OOMKilled=false; start timestamps unchanged |

Exact Pint invocation:

```bash
vendor/bin/pint --test app/ApiTokens/ApiTokenService.php app/ApiTokens/TokenException.php app/Http/Controllers/Api/TokenController.php app/Http/Middleware/ApiBearer.php app/Http/Controllers/Admin/SanctionController.php app/Models/User.php app/Models/Sanction.php bootstrap/app.php config/nosql.php routes/api.php tests/Feature/ApiTokensTest.php
```

Development TDD history, before the three final runs (same command,
`vendor/bin/phpunit tests/Feature/ApiTokensTest.php`, each separate invocation):

| Slice | Exit | Tests / assertions | Result |
|---|---:|---|---|
| Module before implementation | 2 | 1 / 9 | Expected error: service missing |
| Module implemented | 0 | 1 / 14 | Passed |
| HTTP login before routes | 1 | 2 / 24 | Expected 404 failure |
| HTTP route/controller implemented | 0 | 2 / 32 | Passed |
| Existing password action before revocation hook | 1 | 5 / 92 | Expected 200 instead of revoked 401 |
| Password hook implemented | 0 | 5 / 97 | Passed |
| Revocation and expiry slices | 0 | 15 / 309 | Passed |
| Strict TTL datasets before bool/float rejection | 1 | 27 / 504 | Two expected failures (true and 1.0 accepted) |
| Strict TTL/failure/concurrency implemented | 0 | 32 / 611 | Passed |
| Web-boundary and real Redis-session-driver additions | 0 | 34 / 654 | Passed |
| Additional pre-final repeat | 0 | 34 / 654 | Passed; then expanded invalid TTL datasets to both kinds and increased long expiry TTL margin |

Formatter invocations also exited 0: one 11-path formatting pass, then one test-
file formatting pass after the final datasets. The required final Pint check
passed after those edits. No subsequent PHP or Lua implementation edit occurred.

Container start timestamps, unchanged before/after:

- Mongo: 2026-11-14T16:46:53.160790377Z
- Redis: 2026-11-14T16:46:53.161208044Z
- Cassandra: 2026-11-14T16:46:53.162357919Z
- Neo4j: 2026-11-14T16:46:53.161543335Z

### Not verified, limitations and deferred items

- **Curl commands were not executed**, and no HTTP server was started. Demo Bash
  syntax was checked and every HTTP action maps to passing HTTP-kernel tests.
  Provisioning a disposable manual demo instance and owner UI acceptance remain
  separate work; no manual acceptance is claimed.
- No independent Review, deployment, real browser/admin acceptance, sustained-load
  benchmark, network timing proof, Redis failover/ACL/OOM test or Redis Cluster
  support is claimed. The target is the existing standalone Redis deployment.
- Lua rotation is atomic against concurrent requests. Redis does not roll back
  earlier script writes after arbitrary server/ACL failure; relevant key types
  are checked before mutation and all script results/errors are checked. Real
  wrong-type and false EVAL failure paths are tested without restarting stores.
- Revocation hooks run before account persistence and fail closed when Redis is
  unavailable; a later Mongo/SQL write failure can leave tokens safely revoked.
  These are not cross-store transactions and do not cancel a request that already
  authenticated. Serializable concurrent account updates and token issuance are
  not established by this gate's concurrency test (which covers refresh reuse).
- Bulk query-builder writes bypass model events. Existing account actions use
  models and are covered; direct database/bulk callers must invoke the module.
  Deleted/banned identity is still denied on every authenticated request even
  when revocation was missed, as demonstrated by explicit bypass tests.
- Existing SQL-backed sanctions, boosts and password reset tokens remain on their
  current stores; their migration/RBAC/lifecycle work is out of scope. New tests
  isolate them in SQLite :memory:. Story fixtures have pending derived sections,
  so this gate's new tests require only Mongo and Redis containers. No Cassandra
  or Neo4j projection was started; their native health checks are read-only.
- No dedicated admin account-revoke action exists. Permanent ban and admin delete
  are wired. API logout does not revoke a Blade session; the existing Blade
  workflow handles sessions independently. Full session/account deletion retention,
  recovery, purge/anonymization and billing cancellation remain deferred.
- No JWT/OAuth/scopes/registration/device UI, new dependency, compose change or
  rate-limit infrastructure was added. IP throttle uses the existing configured
  Laravel cache store. Finite expiry diagnostics intentionally become invalid
  after grace, and replay after original refresh expiry reports expired.

### PROPOSED ATOMIC COMMIT GROUPING

One atomic Gate 8 commit keeps the implementation, existing-action hooks, tests
and operating documentation reviewable together. The full suite is green for
this exact implementation/test tree (184/184). No commit was created.

1. **feat(auth): add stateless Redis API token families (#22)** -> exact paths:
   - .env.example
   - app/ApiTokens/ApiTokenService.php
   - app/ApiTokens/TokenException.php
   - app/ApiTokens/tokens.lua
   - app/Http/Controllers/Api/TokenController.php
   - app/Http/Controllers/Admin/SanctionController.php
   - app/Http/Middleware/ApiBearer.php
   - app/Models/User.php
   - app/Models/Sanction.php
   - bootstrap/app.php
   - config/nosql.php
   - routes/api.php
   - tests/Feature/ApiTokensTest.php
   - docs/demo/api-token-demo.md
   - docs/database/nosql-local.md
   - .agent/tasks/api-tokens/GATE-REPORT-8.md

Keep the pre-existing TASK.md packet under Control's ownership; it was read but
not modified. Exclude every owner WIP path from staging. The report has no raw
credentials, issued tokens, Redis token hashes or real secret values.

## Review-fix round 1

Date: **2026-11-16**. Control is now **Claude Opus 5.5**, following the owner's
control-model switch. Assigned implementation worker: **gpt-6.1-sol, medium**;
single writer, no delegation. Branch `auth/api-tokens`, HEAD remains
`3149cf959cd62352e85d7ce990858723b72b3b66` (main base); work remains uncommitted.

STATUS: **READY_FOR_REVIEW — findings 1–6 fixed and implementation checks PASS**.
The independent review received for the previous candidate was **FAIL**. This
section supplies implementation evidence for re-review; it does not replace
independent Review, Control acceptance or owner manual acceptance. It supersedes
the earlier candidate's pre-persistence Redis hooks, IP-only throttle, blanket
503 errors, sleep-based expiry tests, limitations and proposed commit grouping.

### Findings, changes and proving tests

All named tests below are in `tests/Feature/ApiTokensTest.php`.

| Finding | Result and change | Proving test |
|---|---|---|
| 1, P1 credential-check/issue race | FIXED. Mongo User `api_token_epoch`, integer 0 when missing; issue preserves the credential-check snapshot's epoch in family JSON. Authenticate and refresh compare it with the current Mongo User. Password update/reset `$set` and epoch `$inc` share one Mongo document write. Active temporary/permanent sanctions increment the epoch in `saved`, after successful sanction persistence. User deletion cleans Redis in `deleted`; a missing User denies access independently. Logout remains Redis family-only. | `test_login_snapshot_cannot_escape_password_change_or_ban_committed_before_issue` interposes a service test double at real controller issue after credential verification, commits password/permanent-ban/temporary-ban changes, then calls real issue; returned AT and RT fail on first use. `test_password_save_increments_stored_epoch_even_from_a_stale_model_snapshot` proves two stale snapshots advance stored epochs 0→1→2. Existing web password/reset tests exercise the real actions. |
| 2, P1 newer warning hides ban | FIXED. isMuted/isBanned/isPermanentlyBanned/isTempBanned each use one relevant active-sanction existence query. canComment/canBookmark/canBoost now derive from those predicates; activeSanction retains its newest-for-display meaning. This also fixes the pre-existing Blade CheckUserSanction bypass. | `test_newer_warning_does_not_mask_active_ban_for_api_or_web` retains a live family, creates permanent ban plus newer warning, confirms warning is displayed while login/bearer deny and `/profile` redirects to the banned route. |
| 3, P2 Redis breaks web security actions | FIXED. Account revoke increments Mongo first; cleanupForUser catches cleanup failures. Password/deletion cleanup runs after successful persistence. Warning context contains operation and exception class only; no exception message, previous exception or Redis diagnostics. ProfileController ordering is unchanged because revoke-before-logout is no longer needed. | `test_web_security_actions_complete_when_token_redis_is_down` forces nosql connection failure for real web password update, self-delete and admin permanent-ban actions. Each completes; surviving old AND rotated ATs plus the live RT fail after Redis is restored. Persisted password/epoch/deletion/ban are checked; injected sensitive diagnostic is absent from captured warnings. |
| 4, P2 exception mapping/reporting | FIXED. Framework HTTP statuses map to unauthenticated/forbidden/not_found/method_not_allowed/rate_limited; authorization 403, model-not-found 404, validation 422 with code only, unexpected/configuration failure 500 server_error. Only token-store failures produce service_unavailable 503. Retry-After/HTTP headers are retained. The API-specific Handler delegates unexpected exceptions to Laravel's normal reporter with a sanitized class-only message and empty context; original messages, context callbacks, request input/headers and traces are excluded. Non-API and no-request reports delegate unchanged. | `test_exception_status_mapping_and_safe_reporting` (8 datasets); `test_validation_values_are_hidden_and_non_api_reporting_and_rendering_are_unchanged` (422 payload, HTML/JSON web 403, web and no-request/console reporter); existing invalid-route/method tests, 16 invalid TTL datasets now expecting 500, false-EVAL and real Redis type-error tests retaining 503. |
| 5, P2 login throttle | FIXED. Validation precedes throttle checks. Only rejected credentials hit 5/min email-hash+IP and 30/min IP counters in the explicit shared Redis cache store. Success clears the account counter and consumes neither. 429 includes Retry-After. | `test_login_limits_count_only_validated_failures_and_isolate_accounts`: malformed input does not consume limit; successful login resets failed attempts; another account behind the same IP can log in; the IP ceiling eventually blocks it. A fresh PHP process reads the same five attempts through the Redis cache store with a live DB15 guard, proving persistence across workers. |
| 6, P3 expiry flakiness / older-generation coverage | FIXED. No short sleeps: fixture helper sets a token deadline to Redis TIME−1 while production Lua still compares actual Redis TIME; live lifetimes are 120/300s in expiry-order tests. Marker retention uses 120/60s. Physical-TTL assertions allow wider elapsed-time margins. Logout, password update/reset, self/admin deletion, permanent ban, temporary ban and explicit revoke include an AT issued before rotation; reuse already covers three generations. | `test_each_token_obeys_its_own_expiry` (both orders), `test_rotated_marker_keeps_original_lifetime_when_new_refresh_lifetime_is_shorter`, existing per-trigger revocation tests and `test_temporary_ban_and_explicit_account_revoke_reject_all_generations_after_ban_lift`. The latter restores retained family JSON after cleanup and lifts the ban, proving epoch checks independently invalidate every access generation and live refresh. |

ApiTokenService is extensible solely to allow a container-bound test double at the
post-credential-check issue seam. No production callback, sleep or request lock
was added. Lua/key layout, rotation and expiry ordering are unchanged except for
family values becoming `{uid,epoch}` and returning the epoch to the service.

### Epoch authority and failure windows

- Password update/reset uses Mongo `$inc` alongside password `$set`, not a second
  write or a stale snapshot's absolute epoch. Legacy missing fields start at 0;
  new User defaults and integer casts document that rule. Redis cleanup follows
  the write. A failed password write cannot persist a new password without the
  corresponding epoch; cleanup failure cannot undo revocation.
- A login can return a family issued after a security write from its earlier
  credential snapshot. Its old epoch makes both tokens unusable at first access
  or refresh. Likewise refresh racing a later epoch bump may return a pair whose
  family is already unusable; no request serialization is required.
- **Updated by Review-fix round 2:** active bans remain enforced by current SQL
  sanctions on every identity check. The Mongo epoch increment now runs in
  Sanction `saving`, BEFORE the SQL write, for every effective active temporary or
  permanent ban (create or update, unexpired or no expiry). A Mongo increment
  failure aborts persistence: no sanction row and no report/comment follow-ups.
  The admin store request redirects with a generic error flash. A SQL failure,
  process crash after the increment, or later SQL rollback can only leave an
  extra safe revocation; old families cannot become valid after lifting a ban.
  Best-effort Redis cleanup runs in `saved` only after successful SQL persistence.
  There is no cross-store transaction and no restoration of the previous epoch.
- A request already authorized before a security write is not cancelled. Redis
  cleanup can safely revoke a newly issued family too if it races cleanup; that
  user must log in again. Safety is favored over preserving a concurrent login.
- User deletion is authoritative through absence from Mongo; cleanup cannot block
  it. Redis still must be reachable for API token lookup/logout/rotation; outage
  produces sanitized 503. Restoring surviving Redis state cannot restore a family
  whose epoch differs. Expired metadata continues to take precedence over revoked
  and reused diagnostics, as before.
- Bulk/query-builder security writes bypass model events and must perform the
  equivalent epoch increment. Existing password/reset/delete/sanction actions use
  models and are covered. No cross-store transaction or rollback is claimed.

### Defects that pre-exist on main — source evidence

Checked directly against HEAD `3149cf9`, rather than relying on the earlier report:

1. `git show HEAD:app/Models/User.php`: isMuted/isBanned/isPermanentlyBanned/
   isTempBanned and permission helpers call activeSanction, which sorts descending
   and returns only one row. Therefore a newer warning hides an older active ban.
   `git show HEAD:app/Http/Middleware/CheckUserSanction.php` uses
   isPermanentlyBanned for its Blade redirect, so the web bypass also pre-exists.
2. `git show HEAD:app/Http/Controllers/Admin/SanctionController.php`: store validates
   `'user_id' => 'required|exists:users,id'`, despite User being pinned to Mongo.
   The Gate 8 candidate corrects it to `exists:mongodb.users,_id`. The real admin
   permanent-ban tests prove Mongo-only User IDs pass without a SQL users row.

The API race, synchronous Redis failures in new revocation hooks, broad API error
mapping and login throttle are defects in the uncommitted Gate 8 candidate; they
are not described as existing-main API defects.

### Verification — separate invocations and true exit codes

All mutating test invocations were sequential. Stores were Mongo `vietfeed_test`,
SQLite `:memory:` and live-guarded Redis DB15 only. The two refresh workers contend
only inside the existing concurrency regression. No FLUSHALL or development Redis
DB0/1/2 mutation, dependency install, server start, restart, commit, stash, push or
delegation was performed.

| Command | Exit | Evidence |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — final run 1 | 0 | 56 tests, 1067 assertions, 3.323s |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — final run 2 | 0 | 56 tests, 1067 assertions, 3.375s |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — final run 3 | 0 | 56 tests, 1067 assertions, 3.134s |
| `vendor/bin/phpunit tests/Feature/Auth` | 0 | 20 tests, 131 assertions |
| `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` | 0 | 4 tests, 47 assertions |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | 13 tests, 343 assertions |
| `composer run test` | 0 | 198 tests, 3089 assertions; config clear then artisan test |
| `vendor/bin/pint --test` — exact 12 paths below | 0 | Passed |
| `git diff --check` | 0 | No whitespace errors; rerun after recording the result |
| `./scripts/nosql-health.sh` | 0 | Mongo/Cassandra/Neo4j/Redis: 4/4 READY; read-only native health checks |
| Round baseline SHA-256 preservation check + demo Bash `bash -n` | 0 | 55 protected existing paths unchanged, including migration-pc; no cached config; demo parser succeeds |

Exact final Pint invocation (all Gate 8 PHP changes, including the new Handler):

```bash
vendor/bin/pint --test app/ApiTokens/ApiTokenService.php app/ApiTokens/TokenException.php app/Exceptions/Handler.php app/Http/Controllers/Api/TokenController.php app/Http/Middleware/ApiBearer.php app/Http/Controllers/Admin/SanctionController.php app/Models/User.php app/Models/Sanction.php bootstrap/app.php config/nosql.php routes/api.php tests/Feature/ApiTokensTest.php
```

Development invocations before the final checks, also without output/exit masking:

| Command | Exit | Evidence |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — first round candidate | 1 | 54 tests, 979 assertions, 51 passed/3 failed: Redis counter returned string; temporary-ban assertion needed epoch code; fixture created_at was not fillable. These test expectations/fixture setup were corrected. |
| Same command — corrected candidate | 0 | 55 tests, 1051 assertions |
| Same command — added stale-model increment regression | 0 | 56 tests, 1063 assertions |
| `vendor/bin/pint` — same 12 paths as final check | 0 | Five files formatted; final check passed afterwards |

No PHP/Lua changes occurred after the three final API invocations. The 55-path
round baseline was captured outside the repository; it covers the existing owner
WIP plus untouched Gate 8 packet/files. The earlier first-run 48-owner-path
preservation claim is historical, not a newly reconstructed baseline.

### Remaining limitations

- Independent re-review is pending; no MANUAL_PASS, merge, deployment or production
  readiness is asserted. Curl/browser walkthrough remains **NOT RUN**; Bash
  syntax and the corresponding HTTP-kernel tests passed. Docs/demo and
  docs/database now describe epochs, error codes and the two failed-login limits.
- No full infrastructure outage/restart, Redis failover/ACL/OOM/Cluster test or
  sustained-load throttle benchmark. Redis failure tests inject a nosql connection
  exception while using real persisted Mongo/SQL users and surviving Redis state.
  Shared-cache counters use real Redis and a separate worker; concurrent throttle
  admission at the boundary is subject to the standard Laravel check/hit race.
- Lua commands remain atomic against concurrent requests, not rollback-safe after
  arbitrary mid-script server faults. The SQL-sanction/Mongo-epoch boundary and
  already-authorized requests have the limits described above.
- API unexpected-error logging intentionally retains exception class and normal
  log routing, without the original exception message/trace or request context.
  Non-API and no-request reporting preserve framework behavior.
- Existing SQL sanctions/boost/reset stores, Blade session lifecycle, bulk callers,
  device/session UI and account retention/purge remain outside this gate.

### Updated PROPOSED ATOMIC COMMIT GROUPING

This supersedes the earlier single-commit proposal. **No commits or staging were
performed.** Keep all owner WIP and `.agent/migration-pc/` excluded.

1. **fix(sanctions): enforce all active sanctions and validate Mongo users** —
   pre-existing-main defects only: the User helper/permission hunks (isMuted,
   isBanned, isPermanentlyBanned, isTempBanned, canComment, canBookmark) and the
   SanctionController `exists:mongodb.users,_id` hunk. Do not include User's Gate 8
   imports, hooks, epoch defaults/casts or getDirtyForUpdate override here. The
   API-facing regression checks depend on Gate 8 and belong to group 2 below;
   split staging by hunk when the owner chooses to commit. The fixes themselves
   have no dependency on Gate 8.
2. **feat(auth): add stateless epoch-bound API token families (#22)** — remaining
   Gate 8 implementation/tests/docs as one reviewable feature: `.env.example`,
   `app/ApiTokens/{ApiTokenService.php,TokenException.php,tokens.lua}`,
   `app/Exceptions/Handler.php`, `app/Http/Controllers/Api/TokenController.php`,
   `app/Http/Middleware/ApiBearer.php`, User epoch/hooks/getDirtyForUpdate hunks,
   `app/Models/Sanction.php`, `bootstrap/app.php`, `config/nosql.php`,
   `routes/api.php`, `tests/Feature/ApiTokensTest.php`,
   `docs/demo/api-token-demo.md`, Gate 8-only `docs/database/nosql-local.md` hunks,
   and this GATE-REPORT-8.md. Include the warning/ban and real admin workflow
   regressions here because they exercise the API implementation.

Keep TASK.md and all other pre-existing packets under their owners. No runtime
credential, issued token or real secret was added to any repository/report file.


## Review-fix round 2

Date: **2026-11-16**. Control: **Claude Opus 5.5**. Single implementation worker;
no delegation. Branch `auth/api-tokens`, HEAD
`3149cf959cd62352e85d7ce990858723b72b3b66`; Gate 8 remains uncommitted.
Reviewer pass 2 resolved prior findings 1–6 but returned **FAIL** on the new P2
ban failure window. This section supersedes round 1's `saved` epoch increment,
failure-window description and proposed commit grouping.

### Per-item implementation result

1. **P2 ban partial success — FIXED.** `app/Models/Sanction.php` increments Mongo
   in `saving` for an effective active temporary/permanent ban, including updates
   to type, activation or expiry. `saved` retains only best-effort Redis cleanup.
   `app/Http/Controllers/Admin/SanctionController.php` catches create failures and
   uses the existing admin redirect/error-flash pattern with a fixed Vietnamese
   message; logs contain exception class only. Validation and follow-up ordering
   are unchanged. Tests in `tests/Feature/ApiTokensTest.php`:
   - `test_admin_ban_mongo_failure_aborts_before_sql_and_preserves_related_state_and_tokens`
     forces a real Mongo increment error using a nonnumeric epoch fixture. The
     response is a redirect with the exact generic flash, no success flash, no
     sanction row, identical report/comment attributes and identical Redis DUMPs
     for every key. Restoring the fixture's original epoch proves pre-rotation
     and current access tokens and the live refresh token still work.
   - `test_admin_ban_sql_failure_only_revokes_existing_families` installs a
     SQLite :memory: trigger that fails INSERT, removed in finally. The request
     gets the same clean error, no row persists, epoch is 1, every old access
     generation and both live refresh families fail; a fresh login succeeds.
   - `test_sanction_updates_revoke_when_the_result_is_an_effective_ban` covers
     warning→permanent ban, inactive→active and expired→future updates. The
     initial nonblocking saves leave epoch 0; each effective ban makes it 1;
     lifting it leaves 1 and old AT/RT remain rejected. Existing normal ban and
     Redis-outage admin HTTP tests remain part of verification.
2. **P3 comment-block display — FIXED.** `User::blockingCommentSanction()` selects
   the newest active mute/temporary_ban/permanent_ban, and
   `resources/views/articles/show.blade.php` uses it for the blocked-comment
   explanation. `activeSanction()` retains its existing general display meaning.
   `test_article_comment_block_displays_older_mute_instead_of_newer_warning`
   makes an actual article GET and checks the mute label, reason and expiry while
   excluding the newer warning reason; it also checks the selected sanction ID.
3. **P3 explicit-null epoch — FIXED.** `User::normalizeApiTokenEpoch()` performs a
   conditional Mongo update of only null/missing epochs to 0 before each `$inc`:
   password save, Sanction saving and `ApiTokenService::revokeAllForUser`.
   The condition is evaluated by Mongo at update time; concurrent numeric epochs
   are never reset, even when the model snapshot is stale. Password `$set` and
   epoch `$inc` remain one document write. A failure after normalization but
   before increment can only change null/missing to the equivalent logical 0;
   it cannot persist a new password or sanction. This small conditional write
   avoids replacing the existing Eloquent update with a custom pipeline.
   `test_explicit_null_epoch_supports_password_ban_and_account_revoke` starts with
   explicit Mongo null for each action, exercises real web password/admin ban
   requests and explicit revoke, checks persisted epoch 1, checks the password,
   lifts the ban, and proves old/rotated ATs and the live RT reject. The existing
   stale-password-snapshot regression still proves epochs advance 0→1→2.

### Test-first evidence

- P2 Mongo-failure regression before fix: exit **1**, actual HTTP 500 and Mongo
  `$inc` exception after SQL persistence. After fixing saving/controller: exit
  **0**, 1 test / 28 assertions.
- P3 overlap regression before fix: exit **1**, article GET rendered the warning
  instead of the mute. After helper/Blade fix: exit **0**, 1 / 16.
- P3 explicit-null regression before fix: exit **1**, password request returned
  500 on Mongo `$inc` of null. After normalization: exit **0**, null-path plus
  stale-snapshot regressions, 2 / 58.
- Additional SQL-failure/update coverage: exit **0**, 2 / 62.

### Final verification

STATUS: **READY_FOR_REVIEW — round 2 items 1–3 fixed; implementation checks PASS**.
Each command was run as its own invocation, sequentially for mutating suites,
without a pipeline or masked exit status. Store guards remained enabled.

| Invocation | True exit code | Result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 1 | 0 | 61 tests / 1,219 assertions |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 2 | 0 | 61 / 1,219 |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 3 | 0 | 61 / 1,219 |
| `vendor/bin/phpunit tests/Feature/Auth` | 0 | 20 / 131 |
| `composer run test` | 0 | 203 / 3,241 |
| `vendor/bin/pint --test` with all Gate 8 PHP/Blade paths below | 0 | PASS |
| `git diff --check` | 0 | PASS |

Exact Pint invocation:

```sh
vendor/bin/pint --test app/ApiTokens/ApiTokenService.php app/ApiTokens/TokenException.php app/Exceptions/Handler.php app/Http/Controllers/Api/TokenController.php app/Http/Controllers/Admin/SanctionController.php app/Http/Middleware/ApiBearer.php app/Models/User.php app/Models/Sanction.php bootstrap/app.php config/nosql.php routes/api.php tests/Feature/ApiTokensTest.php resources/views/articles/show.blade.php
```

All **61 protected files** fingerprinted at round start retain their SHA-256
values, including owner WIP, `.agent/migration-pc/` and Gate 8 files outside this
round's seven allowed paths. Branch/HEAD and the Git index remain unchanged.

### Limitations and acceptance boundary

- Evidence is implementation verification only. Independent re-review, Control
  acceptance, browser/manual acceptance and production behavior are not claimed.
- No Mongo outage/server stop or SQL production fault was induced: tests use a
  real Mongo command error and an isolated SQLite trigger. Only Mongo
  `vietfeed_test`, Redis DB15 and SQLite :memory: are used for tests.
- Mongo and SQL remain separate stores. Increment-before-SQL intentionally permits
  extra revocation if SQL fails; it does not undo already-authorized requests.
  Model-event bypasses still require equivalent revocation by their callers.
- No commit, stash, push, staging, delegation, dependency or runtime configuration
  changes were performed. Owner WIP and `.agent/migration-pc/` stay excluded.

### Final PROPOSED ATOMIC COMMIT GROUPING — exact paths

This supersedes all earlier proposals. Shared paths require staging by hunk;
no commit or staging is performed by this worker.

1. **fix(sanctions): enforce active sanctions and validate Mongo users** — the
   pre-existing defect fixes, with no Gate 8 dependency:
   - `app/Models/User.php`: sanction helpers/permissions only, including
     `blockingCommentSanction`; exclude ApiTokenService import, events, epoch
     default/cast, normalization and getDirtyForUpdate.
   - `resources/views/articles/show.blade.php`: blocked-comment helper call.
   - `app/Http/Controllers/Admin/SanctionController.php`: ONLY the
     `exists:mongodb.users,_id` validation hunk.
2. **feat(auth): add stateless epoch-bound API token families (#22)** — all
   remaining Gate 8 implementation, tests and documentation:
   - `.env.example`
   - `app/ApiTokens/ApiTokenService.php`
   - `app/ApiTokens/TokenException.php`
   - `app/ApiTokens/tokens.lua`
   - `app/Exceptions/Handler.php`
   - `app/Http/Controllers/Api/TokenController.php`
   - `app/Http/Middleware/ApiBearer.php`
   - `app/Models/User.php`: Gate 8 import/events/epoch default/cast/normalization/
     getDirtyForUpdate hunks only.
   - `app/Models/Sanction.php`
   - `app/Http/Controllers/Admin/SanctionController.php`: Log import and generic
     save-error handling only; exclude the Mongo exists-rule hunk.
   - `bootstrap/app.php`
   - `config/nosql.php`
   - `routes/api.php`
   - `tests/Feature/ApiTokensTest.php`: all Gate 8 regressions, including actual
     API/admin/Blade coverage that depends on Gate 8 and group 1.
   - `docs/demo/api-token-demo.md`
   - `docs/database/nosql-local.md`: Gate 8-only documentation hunks.
   - `.agent/tasks/api-tokens/GATE-REPORT-8.md`

Keep `.agent/tasks/api-tokens/TASK.md` and all other owner material out of these
commits.

## Review-fix round 3

Date: **2026-11-16**. Control: **Claude Opus 5.5**. Single implementation worker,
no delegation. Branch `auth/api-tokens`; HEAD remains
`3149cf959cd62352e85d7ce990858723b72b3b66`. This section supersedes round 2's
single pre-write bump, unchanged-active-ban update behavior and lift behavior.

STATUS: **READY_FOR_REVIEW — requested round 3 fixes implemented; verification
PASS; residual failure windows explicitly retained below.** This is implementation
evidence, not independent Review, Control acceptance or owner manual acceptance.

### Changes and proving tests

1. **Ban race / P3 #1 — FIXED under successful epoch writes.**
   `app/Models/Sanction.php` compares the original and proposed effective-ban
   states in `saving` and retains the transition for `saved`. Creating or entering
   an effective temporary/permanent ban bumps Mongo before SQL; failure here
   still aborts the SQL write. After SQL persistence, `saved` bumps again and then
   attempts Redis cleanup. Lifting an effective ban by deactivation, type change
   out of a ban, or moving expiry to the past performs the post-write bump and
   cleanup. Post-write Mongo failures log only a fixed message plus `operation`
   (`ban_saved` / `ban_lifted`) and `exception_type`, then continue the admin action
   and best-effort cleanup. There is no cross-store transaction/barrier.
   - `test_login_between_pre_bump_and_sql_commit_cannot_survive_cleanup_or_admin_lift`:
     both ban types; a test-only `ApiTokenService::issue` override suspends a PHP
     Fiber after the real login controller checks credentials, reads the Mongo
     epoch and reads SQL sanctions. The login starts in Eloquent `creating`,
     after the pre-write bump but before SQL INSERT. It reads epoch 1 with no ban.
     Issuance resumes only after the admin store returns and Redis cleanup has
     completed. Both AT/RT reject with `token_family_revoked` while banned and
     after the real `SanctionController::update` approval/lift. Epoch reaches 3;
     a fresh login and bearer request succeed. No sleeps or production test seam.
   - `test_second_bump_failure_preserves_admin_success_and_blocks_racing_tokens_until_lift`:
     the same Fiber ordering, with an Eloquent `created` callback installing a
     nonnumeric epoch to force a real Mongo increment failure after SQL INSERT.
     The ban persists, admin receives success without error, and the warning's
     entire context is asserted to contain only operation and exception class.
     Mongo is restored to the unchanged pre-write epoch 1 before late Redis
     issuance, proving AT/RT rejection comes from the current SQL ban
     (`account_unavailable`), not an epoch mismatch. The real admin lift advances
     to 2 and both tokens remain rejected.
   - `test_admin_lift_succeeds_and_logs_safely_when_mongo_epoch_bump_fails`:
     real admin approval with a real Mongo increment error; SQL lift persists,
     success is returned and the exact sanitized warning context is asserted.
   - `test_moving_either_ban_expiry_to_the_past_invalidates_families_issued_during_the_ban`:
     both ban types; a family written after creation cleanup is blocked during
     the ban and invalidated when expiry is explicitly written into the past.
     Epoch reaches 3 and a new login succeeds.
   - Existing pre-write Mongo failure, SQL failure, null-epoch, stale snapshot,
     Redis failure and token-generation regressions still pass. Only epoch-count
     expectations affected by the second/lift bumps were updated.
2. **Unchanged effective ban / P3 #2 — FIXED.** A save that leaves the effective
   ban state unchanged skips both epoch hooks and Redis cleanup. This includes
   appeal denial and other metadata-only updates; creating warnings/mutes or
   inactive/expired bans likewise performs no token epoch operation.
   `test_appeal_denial_does_not_touch_mongo_when_epoch_updates_would_fail` first
   creates the effective ban (epoch 2), forces a real Mongo increment failure
   fixture, then uses the real admin denial path. Denial succeeds, the ban stays
   active, the raw epoch is unchanged and no warning is emitted.
3. **View null dereference / P3 #3 — FIXED.**
   `resources/views/articles/show.blade.php` fetches `blockingCommentSanction()`
   once, branches on that result being null, and renders the same captured model.
   `test_article_uses_one_blocking_sanction_snapshot_and_allows_comments_when_it_is_null`
   uses the SQL query-event seam to lift a mute immediately after its SELECT.
   The first real article GET safely renders the captured mute; a second GET,
   with no blocking sanction, renders the comment form. Each GET performs one
   blocking-sanction SELECT. The existing overlapping mute/newer-warning test
   also remains green.

### Test-first evidence and focused checks

All commands below ran as separate invocations with their true exit status.

| Command / filter on `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` | Exit | Result |
|---|---:|---|
| `--filter test_login_between_pre_bump` before sanction fix | 1 | 1 test / 22 assertions; late family incorrectly returned HTTP 200 after real admin lift |
| `--filter test_login_between_pre_bump` after sanction fix | 0 | 1 / 47; later strengthened to require the stale-epoch error while banned |
| `--filter 'test_appeal_denial\|test_second_bump_failure\|test_admin_lift_succeeds\|test_moving_either_ban'` | 0 | 4 / 88 |
| `--filter test_article_uses_one` before view fix | 1 | 1 / 10; HTTP 500, `typeLabel()` called on null |
| `--filter test_article_uses_one` after view fix | 0 | 1 / 17 |

### Final verification

Separate sequential suite invocations; no pipelines, loops masking status, or
parallel store-mutating suites. Existing isolation guards require Mongo
`vietfeed_test`, SQLite `:memory:` and live Redis DB15 before mutations.

| Invocation | True exit code | Result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 1 | 0 | 67 tests / 1,381 assertions |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 2 | 0 | 67 / 1,381 |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 3 | 0 | 67 / 1,381 |
| `vendor/bin/phpunit tests/Feature/Auth` | 0 | 20 / 131 |
| `composer run test` | 0 | 209 / 3,403 |
| `vendor/bin/pint --test` with the exact paths below | 0 | PASS; all 13 Gate 8 PHP/Blade files |
| `git diff --check` | 0 | PASS |

Exact Pint invocation:

```sh
vendor/bin/pint --test app/ApiTokens/ApiTokenService.php app/ApiTokens/TokenException.php app/Exceptions/Handler.php app/Http/Controllers/Api/TokenController.php app/Http/Controllers/Admin/SanctionController.php app/Http/Middleware/ApiBearer.php app/Models/User.php app/Models/Sanction.php bootstrap/app.php config/nosql.php routes/api.php tests/Feature/ApiTokensTest.php resources/views/articles/show.blade.php
```

Round 3 changes are restricted to these four paths:

- `app/Models/Sanction.php`
- `resources/views/articles/show.blade.php`
- `tests/Feature/ApiTokensTest.php`
- `.agent/tasks/api-tokens/GATE-REPORT-8.md` (append only)

All **65 protected files** fingerprinted at round start, including the Git index,
owner WIP, `.agent/migration-pc/` and other Gate 8 files, retain their SHA-256
values. No commit, staging, stash, push, delegation, dependency, active `.env`,
compose or service lifecycle changes were performed.

### Remaining windows and limitations

- **Residual security/revocation gap, not fully fail-closed:** pre-write bump
  succeeds; a racing login reads that new epoch and no SQL ban; SQL ban persists;
  the second Mongo bump fails; Redis issuance lands after successful cleanup (or
  cleanup itself fails). That family has the current epoch. Authoritative SQL
  checks reject it while the ban is effective, but if a temporary ban expires
  solely by time with no write, no lift hook runs. The still-live AT/RT can work
  again after expiry, until a later successful epoch bump, family revocation or
  their own expiry. A usable RT can rotate into further tokens, so the initial
  access-token TTL alone does not bound this gap. This is an acknowledged
  cross-store failure-mode limitation of Control's no-barrier design, not a
  claim that all ban-time families are unconditionally destroyed. It requires
  the failed second bump plus a surviving racing issuance, not ordinary expiry
  after two successful bumps. The report classifies this from the explicit
  state/interleaving; the failure test covers rejection while banned and a real
  write-based lift, not a natural-time-expiry execution.
- A **successful write-based lift bump** invalidates every family with the prior
  epoch, including the above survivor. If the lift bump also fails, the lift
  intentionally still succeeds; cleanup is best effort, and a family that
  survives cleanup or lands after it can remain usable after lift. The sanitized
  warning is diagnostic evidence, not automatic repair/retry infrastructure.
- A pre-write Mongo failure aborts the ban; SQL failure after a successful
  pre-bump can revoke old families even though no ban persists. Already-authorized
  requests and callers bypassing model events remain outside these hooks.
  `saved` fences persistence in the existing admin path; it is not an outer SQL
  transaction after-commit barrier for future callers wrapping model saves.
- Faults were injected in isolated test stores (real Mongo command errors,
  existing SQLite failure trigger, existing Redis failure seams). No production
  outage, browser/manual acceptance or independent review is claimed. The new
  concurrency/view regressions use deterministic event/Fiber seams and no waits;
  pre-existing expiry tests retain their existing Redis TTL waits.

### Final PROPOSED ATOMIC COMMIT GROUPING — exact paths

Supersedes earlier proposals. Owner decides staging/committing; shared paths
must be split by hunk. No commits or staging were performed.

1. **fix(sanctions): enforce active sanctions and validate Mongo users**
   - `app/Models/User.php`: sanction helper/permission hunks, including
     `blockingCommentSanction`, only; exclude all Gate 8 epoch/hooks hunks.
   - `resources/views/articles/show.blade.php`: single blocking-sanction snapshot
     and null/allowed-comment branch, including round 3's view repair.
   - `app/Http/Controllers/Admin/SanctionController.php`: only the
     `exists:mongodb.users,_id` validation hunk.
2. **feat(auth): add stateless epoch-bound API token families (#22)**
   - `.env.example`
   - `app/ApiTokens/ApiTokenService.php`
   - `app/ApiTokens/TokenException.php`
   - `app/ApiTokens/tokens.lua`
   - `app/Exceptions/Handler.php`
   - `app/Http/Controllers/Api/TokenController.php`
   - `app/Http/Middleware/ApiBearer.php`
   - `app/Models/User.php`: Gate 8 import/events/epoch default/cast/normalization/
     getDirtyForUpdate hunks only.
   - `app/Models/Sanction.php`: includes the round 3 transition/bracketing/lift
     hooks and sanitized post-write failure handling.
   - `app/Http/Controllers/Admin/SanctionController.php`: Log import and generic
     save-error handling only; exclude group 1's Mongo validation hunk.
   - `bootstrap/app.php`
   - `config/nosql.php`
   - `routes/api.php`
   - `tests/Feature/ApiTokensTest.php`: all Gate 8 tests, including round 3;
     depends on group 1's helpers/view fixes.
   - `docs/demo/api-token-demo.md`
   - `docs/database/nosql-local.md`: Gate 8-only hunks.
   - `.agent/tasks/api-tokens/GATE-REPORT-8.md`

Exclude `.agent/tasks/api-tokens/TASK.md`, `.agent/migration-pc/`, and all other
owner material from both proposed groups.


## Review-fix round 4

Date: **2026-11-16**. Control: **Claude Opus 5.5**. Single implementation worker;
no delegation. Branch `auth/api-tokens`; HEAD remains
`3149cf959cd62352e85d7ce990858723b72b3b66`; work remains uncommitted.

STATUS: **READY_FOR_REVIEW**. Both requested P3 notes are implemented and verified.
This is implementation evidence, not independent Review or manual acceptance.

### Per-item results

1. **Effective-ban deletion — FIXED.** `Sanction::deleted` checks the persisted
   original effective-ban state, performs the lift epoch bump, logs a fixed
   warning with only `operation=ban_lifted` and `exception_type` on failure, and
   continues to best-effort Redis cleanup. The existing admin destroy action
   succeeds even if Mongo's increment fails. Real admin DELETE route tests cover
   temporary/permanent bans, epoch 2 -> 3, Redis family-index cleanup, rejection
   of both AT/RT issued during the ban, and successful fresh login. A real Mongo
   increment-failure test proves deletion still succeeds, the exact warning
   context is sanitized, Redis is cleaned and both tokens are rejected.
2. **Sanction user reassignment — FIXED.** An `updating` hook throws
   `LogicException` when `user_id` is dirty. The test attempts reassignment with
   a simultaneous reason change, catches the exception, reloads the entire row
   and proves no SQL attributes persisted; both users' epochs remain unchanged.

### Test-first evidence

Separate command invocations, each with its true exit code:

| Focused command (`vendor/bin/phpunit tests/Feature/ApiTokensTest.php`) | Exit | Result |
|---|---:|---|
| `--filter test_admin_deleting_an_effective_ban` before fix | 1 | 1 test / 17 assertions; epoch stayed 2 instead of 3 |
| Same filter after fix | 0 | 1 / 37 |
| `--filter test_sanction_user_reassignment` before fix | 1 | 1 / 10; reassignment was accepted |
| Combined three round 4 tests after guard | 1 | 3 / 70; reload assertion needed a database-loaded baseline to include SQL defaults/casts |
| Combined three round 4 tests after baseline correction | 0 | 3 / 72 |

### Verification

Every requested command ran as a separate sequential invocation; no status-
masking pipelines or parallel store-mutating suites. Existing guards enforce
Mongo `vietfeed_test`, SQLite `:memory:` and live Redis DB15 before mutation.

| Invocation | True exit | Result |
|---|---:|---|
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 1 | 0 | 70 tests / 1,453 assertions |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 2 | 0 | 70 / 1,453 |
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` — run 3 | 0 | 70 / 1,453 |
| `composer run test` | 0 | 212 / 3,475 |
| `vendor/bin/pint --test app/Models/Sanction.php tests/Feature/ApiTokensTest.php` | 0 | PASS |
| `git diff --check` | 0 | PASS |

Composer emitted a non-fatal unwritable cache-directory notice and completed
successfully. Round 4 changes only:

- `app/Models/Sanction.php`
- `tests/Feature/ApiTokensTest.php`
- `.agent/tasks/api-tokens/GATE-REPORT-8.md` (append only)

All **373 protected files**, including the Git index and existing owner WIP,
retain their start-of-round SHA-256 fingerprints. No staging, commit, stash,
push, dependency, active `.env`, compose or service lifecycle changes occurred.
Round 3's documented cross-store failure windows remain: failed lift bumps do
not block the admin action; Redis cleanup remains best effort. No new barrier,
retry mechanism or production/manual acceptance is claimed.

### Final PROPOSED ATOMIC COMMIT GROUPING — exact paths

Supersedes earlier proposals; owner controls staging and commits. Split shared
paths by hunk. No commits were created.

1. **fix(sanctions): enforce active sanctions and validate Mongo users**
   - `app/Models/User.php`: sanction helpers/permissions, including
     `blockingCommentSanction`, only; exclude Gate 8 epoch/hooks hunks.
   - `resources/views/articles/show.blade.php`: single blocking-sanction snapshot
     and null/allowed-comment branch, including round 3's view repair.
   - `app/Http/Controllers/Admin/SanctionController.php`: only the
     `exists:mongodb.users,_id` validation hunk.
2. **feat(auth): add stateless epoch-bound API token families (#22)**
   - `.env.example`
   - `app/ApiTokens/ApiTokenService.php`
   - `app/ApiTokens/TokenException.php`
   - `app/ApiTokens/tokens.lua`
   - `app/Exceptions/Handler.php`
   - `app/Http/Controllers/Api/TokenController.php`
   - `app/Http/Middleware/ApiBearer.php`
   - `app/Models/User.php`: Gate 8 import/events/epoch default/cast/normalization/
     getDirtyForUpdate hunks only.
   - `app/Models/Sanction.php`: all Gate 8 hooks, including round 4's deleted
     hook and immutable-user guard.
   - `app/Http/Controllers/Admin/SanctionController.php`: Log import and generic
     save-error handling only; exclude group 1's Mongo validation hunk.
   - `bootstrap/app.php`
   - `config/nosql.php`
   - `routes/api.php`
   - `tests/Feature/ApiTokensTest.php`: all Gate 8 tests, including round 4;
     depends on group 1's helpers/view fixes.
   - `docs/demo/api-token-demo.md`
   - `docs/database/nosql-local.md`: Gate 8-only hunks.
   - `.agent/tasks/api-tokens/GATE-REPORT-8.md`

Exclude `.agent/tasks/api-tokens/TASK.md`, `.agent/migration-pc/`, and all other
owner material from both groups.

## Independent review (gpt-6.1-terra, read-only, sha256-pinned file sets)

Control changed mid-gate: Fable 5.2 started the gate on 2026-11-15; the owner switched the control session to Claude Opus 5.5
before review-fix round 1. Worker and reviewer models stayed `gpt-6.1-sol` and `gpt-6.1-terra` throughout.

| Pass | Verdict | Findings |
|---|---|---|
| 1 | FAIL | P1 login racing a password change could issue a family that survives revocation; P1 a newer warning/mute masked an active ban (pre-existing on main, web affected too); P2 Redis outage broke web security actions; P2 every unmapped API error became 503 and went unreported; P2 IP-only login throttle counted successes; P3 timing-sensitive tests, older access generation not covered |
| 2 | FAIL | All six resolved. New P2: a ban could partially succeed (SQL sanction saved, Mongo epoch increment failed). P3 comment explanation showed the wrong sanction; P3 explicit null epoch |
| 3 | FAIL | New P1 from the round-2 ordering: a login between the pre-write epoch bump and the SQL commit could issue a family that works again after the ban is lifted. P3 appeal-only updates touched Mongo; P3 view null dereference |
| 4 | PASS_WITH_NOTES | Race resolved under successful epoch writes; remaining windows acceptable as documented P3. New P3: admin delete of a ban had no lift bump; `user_id` reassignment not fenced |
| 5 | PASS_WITH_NOTES | Both resolved. Note: the `deleted` hook reads the current `user_id`, so code that changes it in memory and then deletes without saving would bump the wrong user |

Design decisions made by control during review: MongoDB `api_token_epoch` on User is the revocation authority and Redis
deletion is best-effort cleanup (round 1); the epoch is bumped before the SQL ban write so a Mongo failure aborts the ban
(round 2); the ban is bracketed by a second bump after the commit and lifted bans bump again, instead of a cross-store
barrier (round 3).

Control ruling on the pass-5 note: accepted as a theoretical P3, not fixed. No code path in the application changes a
sanction's `user_id` in memory and then deletes it; reassignment through `save()` is already rejected.

Remaining documented limitations: a failed second bump coinciding with a racing login, followed by a temporary ban that ends
by time (no write), can leave that racing family usable; a failed lift bump leaves surviving families until their own
expiry; query-builder writes that change passwords or sanctions bypass the model hooks.

## Control final gate (Claude Opus 5.5, final tree, 2026-11-16)

| Command | Exit | Result |
|---|---|---|
| `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` x3 | 0 | 70 tests / 1453 assertions each |
| `vendor/bin/phpunit tests/Feature/Auth` | 0 | 20 / 131 |
| `vendor/bin/phpunit tests/Feature/RedisSessionTest.php` | 0 | 4 / 47 |
| `vendor/bin/phpunit tests/Feature/RedisQueueTest.php` | 0 | 3 / 42 |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` | 0 | 29 / 495 |
| `vendor/bin/phpunit tests/Feature/StoryPageTest.php` | 0 | 13 / 343 |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` | 0 | 16 / 308 |
| `vendor/bin/phpunit tests/Feature/AdminArticleDefectsTest.php` | 0 | 18 / 168 |
| `composer run test` | 0 | 212 / 3475 |
| `vendor/bin/phpunit tests/Integration/StoryProjectionsTest.php` | 0 | 24 / 445 |
| `vendor/bin/phpunit tests/Integration/StoryPageProjectionsTest.php` | 0 | 2 / 111 |
| Mongo integration (connection + auth double) | 0 | 3 / 19 |
| `composer check-platform-reqs`, `composer validate`, Pint on every Gate 8 PHP file, `git diff --check`, store health | 0 | pass |

No container restarts or OOM kills. Not verified: the `curl` walkthrough was not executed against a running server, and
only the final tree was gated, not each commit.

Verdict: PASS_WITH_NOTES.
