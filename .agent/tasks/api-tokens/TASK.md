# Gate 8 — First-party API token flow (Access Token / Refresh Token on Redis)

Canonical sources, in precedence order: `docs/specs/vietfeed-nosql-storylens.md` (§7 story 17, §9.4, §11 "API Token Service",
§12.4, §15, §16 item 11), `docs/planning/technical-gates.md` Gate 8, `docs/planning/project-scope-notes.md` (API token notes),
`docs/architecture/database-feature-map.md` (first-party `/api/v1` authentication row), `docs/demo/failure-scenarios.md`
Scenarios 2, 3 and 4.

One gate, one branch, one PR: branch `auth/api-tokens`, base `main` 3149cf9.

Goal: a narrow `/api/v1` surface protected by VietFeed-issued opaque Access/Refresh Token pairs whose hashes and family state
live in Redis with second-based TTLs, independent of the Blade session, with rotation, reuse detection, expiry and
account-wide revocation proven by tests and a deterministic `curl` demo.

## Required outcomes

1. **API Token Service (domain module)** — one module owning issue, authenticate, refresh, revoke-family and revoke-all-for-user.
   Controllers, middleware and listeners call it; nothing else touches the token keys. Redis access goes through the existing
   `nosql` connection and the checked-result helper (`App\Interaction\RedisResult`: phpredis returns `false` instead of
   throwing).
2. **Opaque tokens, hashes only** — tokens are cryptographically random (at least 256 bits), returned to the client exactly
   once, never stored, logged, put in exceptions, URLs or events. Redis holds only a hash of each token plus family state.
   A test scans every key and value in the test Redis DB (and the log output of the flow) for the raw token strings.
3. **TTL in seconds from the environment** — `AUTH_ACCESS_TOKEN_TTL_SECONDS` and `AUTH_REFRESH_TOKEN_TTL_SECONDS` (names from
   failure-scenarios.md), read through config, validated as positive integers, with safe defaults in config and placeholders
   only in `.env.example`. Each token obeys its own lifetime.
4. **Endpoints under `/api/v1`** (JSON only, no session, no cookies, no CSRF):
   `POST auth/login`, `POST auth/refresh`, `POST auth/logout`, `GET auth/me`, one Story read endpoint (reuse
   `App\StoryLens\StoryLens`), and one authenticated interaction endpoint (reuse an existing interaction, do not invent a
   new feature). Protected endpoints take `Authorization: Bearer <access token>`.
5. **Login** — email + password against the MongoDB User; generic failure (`401 invalid_credentials`) that does not reveal
   whether the email exists; throttled; a permanently banned / revoked account cannot log in. Starts a new token family.
6. **Refresh rotation** — a Refresh Token works exactly once and returns a new pair in the same family; the old Access Token
   of that family stops working at rotation or keeps its own TTL — choose, justify and test the choice. Rotation must be
   atomic under two concurrent requests with the same Refresh Token: at most one succeeds.
7. **Reuse detection** — presenting an already-rotated Refresh Token revokes the whole family: the replacement Access and
   Refresh Tokens stop working immediately (Scenario 3). The rotated-token marker must survive long enough to detect reuse
   for the original lifetime of that Refresh Token.
8. **Expiry semantics (Scenario 2)** — with Refresh TTL shorter than Access TTL (test configuration): after the Refresh Token
   expires the still-valid Access Token keeps working on `auth/me` and `auth/refresh` returns `401 refresh_token_expired`;
   after the Access Token also expires `auth/me` returns `401 access_token_expired`. Tampered or unknown tokens return
   `401 invalid_access_token` / `401 invalid_refresh_token` (Scenario 3). Decide how "expired" is distinguished from
   "unknown" without keeping state forever, bound it, and document it.
9. **Revocation** — logout revokes the presented family. Password change (profile password update and password reset),
   permanent ban / account revoke by an admin, and user-initiated account deletion revoke every family of that user
   immediately (Scenario 4), through the existing code paths for those actions. The Blade session is handled by its own
   workflow and is not changed here.
10. **MongoDB stays authoritative for identity** — every authenticated API request loads the current User from MongoDB; a
    deleted or banned user fails even if revocation was somehow missed. No permission or role is trusted from Redis.
11. **Generic errors** — a stable JSON error shape with a machine code; bodies never contain token hashes, Redis keys,
    credentials, stack traces, or hints about how close a guessed token was. Unknown routes and validation errors under
    `/api/v1` are JSON too.
12. **Isolation from the web app** — existing web routes, sessions and middleware behave as before; the web-only sanction
    middleware is not silently applied or skipped incorrectly; existing tests stay green.
13. **Tests** — feature tests in `composer run test` (MongoDB `vietfeed_test`, Redis DB15 behind the live guard) for every
    outcome above: login, refresh rotation, concurrent refresh, reuse detection, both expiry orders, logout, each
    account-wide revocation trigger, tampered tokens, raw-token absence, TTL values actually set in Redis, throttling,
    and the two non-auth endpoints. Expiry tests must be deterministic (controlled clock or TTLs of a second or two with
    explicit waits; say which and keep the suite fast).
14. **Docs** — `docs/demo/api-token-demo.md`: a deterministic `curl` walkthrough of Scenarios 2, 3 and 4 that captures tokens
    in shell variables and never prints, commits or logs raw credentials; Redis key layout, TTL rules and the native
    `redis-cli` inspection commands in `docs/database/nosql-local.md`; `GATE-REPORT-8.md` in this folder.

## Test isolation (hard requirements)

MongoDB `vietfeed_test`, SQLite `:memory:`, Redis DB15 behind the existing live `getDbNum()` guard. No Cassandra or Neo4j is
needed for this gate. Never touch dev Redis DB0/1/2.

## Non-goals

JWT, OAuth2 scopes, Laravel Sanctum/Passport or any new dependency, an SPA or any change to Blade authentication, Google login
through the API, registration through the API, RBAC permission model (later gate), device/session management UI, the 30-day
account deletion lifecycle (only the immediate token revocation hook), Stripe, rate-limit infrastructure beyond login.

## Stop conditions

Product-scope change required; need to edit owner WIP; a failure not fixable in scope; container restart or OOM.
