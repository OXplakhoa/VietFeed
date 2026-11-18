# Gate 8 — first-party API token demo

These commands are a walkthrough for a **separately provisioned, disposable demo
instance**. They were not executed in Gate 8: the equivalent HTTP tests passed.
Do not run a server against development data for this verification.

Control/demo setup: provide a factory-created Mongo User with a known password,
a second admin for the existing sanction action, one Story UUID and one Article
UUID. Sanctions and boosts are Mongo collections (Gate 9b / 9.5; no SQL since
slice 5); run `php artisan migrate --force` for their indexes. No registration API is added.
Use an otherwise idle, loopback demo instance with a fresh login throttle window.
Configure its process environment, without changing the owner's active `.env`:

```dotenv
AUTH_ACCESS_TOKEN_TTL_SECONDS=15
AUTH_REFRESH_TOKEN_TTL_SECONDS=5
```

For the automated equivalent, `tests/Feature/ApiTokensTest.php` supplies all fixtures
using Mongo `vietfeed_test` and guarded Redis DB15 (no SQL connection). It needs no
HTTP server. Run it sequentially; it clears DB15 and removes test Mongo fixtures.
The walkthrough below assumes Bash, curl and Python 3. Run in a fresh disposable
Bash subshell (`bash`); a failed login exits only that subshell. Disable shell tracing;
responses containing tokens remain in shell variables, never files or terminal
output. Secrets are read interactively and sent through standard input; they do
not appear in curl arguments. Do not use curl's verbose/trace options.

```bash
set +x
read -r -p 'Disposable instance base URL (for example http://127.0.0.1:8088): ' BASE_URL
read -r -p 'Factory user email: ' API_EMAIL
read -r -s -p 'Factory user password: ' API_PASSWORD; printf '\n'
read -r -p 'Fixture Story UUID: ' STORY_ID
read -r -p 'Fixture Article UUID: ' ARTICLE_ID

# Never echo the issuing response. Output only the non-secret machine status.
login() {
  local result
  result="$(printf '%s\0%s' "$API_EMAIL" "$API_PASSWORD" |
    python3 -c 'import sys,json; e,p=sys.stdin.read().split("\0",1); print(json.dumps({"email":e,"password":p}))' |
    curl -sS -H 'Content-Type: application/json' --data-binary @- "$BASE_URL/api/v1/auth/login")" || return
  if ! printf '%s' "$result" | python3 -c 'import sys,json; d=json.load(sys.stdin); sys.exit(0 if "access_token" in d else 1)'; then
    printf 'Login failed; inspect instance configuration/throttle without printing credentials.\n'; return 1
  fi
  AT="$(printf '%s' "$result" | python3 -c 'import sys,json; print(json.load(sys.stdin)["access_token"])')"
  RT="$(printf '%s' "$result" | python3 -c 'import sys,json; print(json.load(sys.stdin)["refresh_token"])')"
  printf 'login: ok\n'
}
me() {
  printf 'Authorization: Bearer %s\n' "$AT" |
    curl -sS -w '\nHTTP %{http_code}\n' -H @- "$BASE_URL/api/v1/auth/me"
}
refresh() {
  local result code
  result="$(printf '%s' "$RT" |
    python3 -c 'import sys,json; print(json.dumps({"refresh_token":sys.stdin.read()}))' |
    curl -sS -H 'Content-Type: application/json' --data-binary @- "$BASE_URL/api/v1/auth/refresh")" || return
  code="$(printf '%s' "$result" | python3 -c 'import sys,json; d=json.load(sys.stdin); print("ok" if "access_token" in d else d.get("error",{}).get("code","unexpected_response"))')"
  if [ "$code" = ok ]; then
    AT="$(printf '%s' "$result" | python3 -c 'import sys,json; print(json.load(sys.stdin)["access_token"])')"
    RT="$(printf '%s' "$result" | python3 -c 'import sys,json; print(json.load(sys.stdin)["refresh_token"])')"
  fi
  printf 'refresh: %s\n' "$code"
}

# Minimum protected read and existing interaction. No Blade cookies/CSRF needed.
login || exit 1
me                                      # 200, current Mongo identity
printf 'Authorization: Bearer %s\n' "$AT" |
  curl -sS -w '\nHTTP %{http_code}\n' -H @- "$BASE_URL/api/v1/stories/$STORY_ID"
printf 'Authorization: Bearer %s\n' "$AT" |
  curl -sS -w '\nHTTP %{http_code}\n' -H @- -H 'Content-Type: application/json' \
    --data "{\"article_id\":\"$ARTICLE_ID\"}" "$BASE_URL/api/v1/boosts/toggle"
# A fresh verified fixture adds the boost; a second identical call removes it.
```

Scenario 2 (finish each command promptly; the first wait is inside the 15-second
access lifetime):

```bash
login || exit 1
me                                      # 200
sleep 6
me                                      # 200: expired refresh does not revoke access
refresh                                 # 401 refresh_token_expired (code only printed)
sleep 10
me                                      # 401 access_token_expired
```

Both lifetimes are checked using Redis server time, not the PHP clock. Automated
expiry tests use 1/4-second lifetimes and explicit 2-second waits, in both orders.
Expired metadata is retained for 60 seconds; afterwards the response is invalid
rather than expired. Re-running this snippet requires a fresh login.

Scenario 3 (tamper, rotate, then replay):

```bash
login || exit 1
GOOD_AT="$AT"; AT="${AT}x"
me                                      # 401 invalid_access_token
AT="$GOOD_AT"
GOOD_RT="$RT"; RT="${RT}x"
refresh                                 # 401 invalid_refresh_token
RT="$GOOD_RT"
OLD_AT="$AT"; OLD_RT="$RT"
refresh                                 # 200: new pair, same family
NEW_AT="$AT"; NEW_RT="$RT"
AT="$OLD_AT"; me                        # 200 until its own 15-second expiry
AT="$NEW_AT"
RT="$OLD_RT"; refresh                   # 401 refresh_token_reused; entire family revoked
me                                      # 401 token_family_revoked
RT="$NEW_RT"; refresh                   # 401 token_family_revoked
```

Do this sequence within the original 5-second refresh lifetime. For a slower
presentation, use normal lifetimes (900/2592000 seconds) on the isolated instance
for Scenarios 3–4. All secret-bearing successful responses remain captured by the
functions; failure bodies use only the error code. Parallel reuse is covered by
two independent HTTP-kernel processes in the concurrency test.

Scenario 4 (existing account action; do not invent an API admin endpoint):

```bash
login || exit 1
me                                      # 200
# Immediately, in a separate existing admin Blade session, apply a permanent ban
# to this fixture user through /admin/sanctions. Do not paste AT or RT into the UI.
me                                      # 401 token_family_revoked while access lifetime remains
refresh                                 # 401 token_family_revoked
```

For this manual step, use normal token lifetimes so UI time cannot cause expiry.
The automated `test_admin_permanent_ban_revokes_all_families_and_denies_login`
posts the actual existing admin action and proves the result without a clock race.
Separate tests prove password update/reset and self/admin deletion revoke **all**
families. There is no standalone admin account-revoke action in the current app.
The existing Blade sanction workflow redirects the sanctioned session to `/banned`;
API logout does not log out Blade, and this gate adds no Blade session-revocation
mechanism. Full lifecycle/session coordination is deferred.

Optional family-only logout, before banning the user:

```bash
printf 'Authorization: Bearer %s\n' "$AT" |
  curl -sS -w '\nHTTP %{http_code}\n' -H @- -X POST "$BASE_URL/api/v1/auth/logout"
me                                      # 401 token_family_revoked
unset API_PASSWORD API_EMAIL AT RT GOOD_AT GOOD_RT OLD_AT OLD_RT NEW_AT NEW_RT
```

Restore the isolated instance's normal lifetime configuration after Scenario 2.
No server, curl request, fixture provisioning, admin browser action or environment
change in this walkthrough was executed as part of implementation verification.

| Walkthrough action | Passing HTTP test in ApiTokensTest |
|---|---|
| Login, me, no-store, no cookies | test_http_login_is_stateless_and_returns_no_store_tokens |
| Story and add/remove boost | test_story_read_and_existing_boost_toggle_use_bearer_identity_and_current_policy |
| Scenario 2, either expiry order | test_each_token_obeys_its_own_expiry |
| Tampered access/refresh | test_invalid_and_cross_kind_tokens_and_generic_json_errors |
| Rotation, old access, replay | test_rotation_preserves_old_access_but_reuse_revokes_every_generation |
| Parallel refresh | test_concurrent_http_refresh_requests_allow_at_most_one_success_and_revoke_on_reuse |
| Permanent ban | test_admin_permanent_ban_revokes_all_families_and_denies_login |
| Password update/reset, self/admin deletion | test_web_password_update_revokes_all_families; test_password_reset_revokes_all_families_through_existing_web_action; test_self_deletion_revokes_every_family; test_admin_deletion_revokes_every_family |
| Logout | test_logout_revokes_only_presented_family |

Review-fix round 1: account revocation uses Mongo `api_token_epoch`, default 0,
with Redis deletion as best-effort cleanup. A password update/reset increments the
epoch in the password write; an active temporary/permanent ban increments it after
the sanction save. Deleted users fail the Mongo lookup. Redis outage therefore
cannot block these web actions. Restoring Redis cannot restore old families whose
epoch differs: access and refresh return `401 token_family_revoked` (deleted User:
`401 account_unavailable` if Redis metadata survives). Logout remains family-only.
A newer active warning/mute cannot mask a ban in API or Blade policy checks.

Only validated failed credential checks count toward login limits: 5/minute per
email hash + IP and 30/minute per IP using shared Redis cache. Successful login
clears the account counter. Malformed input returns 422 without consuming it;
429 `rate_limited` includes `Retry-After`. Wait for that delay before repeating.
Unexpected failures and invalid TTL configuration return 500 `server_error`;
token-store failures alone return 503 `service_unavailable`. Framework 401/403/
404/405/429 and validation 422 retain their proper status and generic machine code.
No curl syntax change is required by these rules. Automated expiry tests now use
controlled Redis fixture deadlines with real Lua TIME comparisons and wide live
TTL margins; the manual walkthrough above still uses actual wall-clock expiry.
