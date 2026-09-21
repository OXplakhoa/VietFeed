# VietFeed NoSQL — Failure and recovery demo

> Supporting failure scenarios for `docs/specs/vietfeed-nosql-storylens.md`. The canonical spec defines the required contracts.

The final demo includes controlled failures that prove the system's stated consistency and security contracts. Use deterministic local data and never expose real credentials in commands, screenshots, recordings, or committed files.

## Scenario 1: Neo4j projection temporarily unavailable

1. Stop or disable the Neo4j projection consumer.
2. Replay a new breaking-news fixture.
3. Show that MongoDB stores the canonical Article/Story and the first render remains available.
4. Show the Story graph section as `pending`/`failed`, not as complete.
5. Show the failed projection in the admin processing view/dead-letter queue.
6. Restore the consumer and trigger retry/replay.
7. Show the graph becoming `ready` without re-ingesting the canonical Article.

This demonstrates source ownership, progressive rendering, idempotent consumers, retry and recovery.

## Scenario 2: API Access/Refresh Token expiry

Use an intentionally short, local-only configuration expressed in seconds, for example:

```dotenv
AUTH_ACCESS_TOKEN_TTL_SECONDS=15
AUTH_REFRESH_TOKEN_TTL_SECONDS=5
```

Demonstrate with `curl`:

1. Log in and capture the returned Access Token and Refresh Token in shell variables; do not print/store them in a committed script or log.
2. Call `/api/v1/auth/me` while the Access Token is valid.
3. After the Refresh Token has expired but before the Access Token expires, show that `/me` still succeeds and `/auth/refresh` returns a generic `401 refresh_token_expired` response.
4. After the Access Token also expires, show that `/me` returns `401 access_token_expired` and a fresh login is required.
5. Restore normal TTL configuration after the demo.

This demonstrates that each token obeys its own lifetime and that Refresh Token expiry does not retroactively invalidate an otherwise valid Access Token.

## Scenario 3: Invalid and replayed credentials

- Modify an Access Token and show `401 invalid_access_token`.
- Modify a Refresh Token and show `401 invalid_refresh_token`.
- Refresh once successfully, then reuse the previous Refresh Token and show that the entire token family is revoked.
- Verify that the replacement Access Token/Refresh Token can no longer extend the compromised family according to the documented revocation policy.

Error bodies must be generic and must not reveal token hashes, lookup keys, credentials, stack traces, or whether a guessed raw token was close to a valid value.

## Scenario 4: Administrative/user revocation

1. Issue an API token pair for a test user.
2. Revoke/sanction or soft-delete that user.
3. Show that subsequent API requests fail immediately even when the original Access Token TTL has not elapsed.
4. Show that the Blade session is revoked independently through the account workflow.

This demonstrates that account state in MongoDB remains authoritative while Redis supplies immediate session/token revocation.
