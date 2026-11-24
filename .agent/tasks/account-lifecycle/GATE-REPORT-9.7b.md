# Gate 9.7b — Slice A report (account deletion lifecycle)

Branch `account/lifecycle` (base `main` 7ce33c8). Implementer: Claude Sonnet 5.5. Slice B (outcomes 8–9) is not part of this report.
Nothing was committed or pushed. No command was run against dev data; every test ran on the forced isolated stores
(Mongo `vietfeed_test`, Cassandra `vietfeed_test`, Redis DB15) with `FakeStripeClient` (no network).

## Decision log

Followed TASK.md "Control decisions" as written, except where listed here.

| # | Decision / deviation | Why |
|---|---|---|
| 1 | Worker discovery is by **workflow** (as specified). The workflow is written **before** the user document is flagged; an orphan (user write never landed, or recovery crashed) is deleted by the worker (after 10 min when it still lacks the Stripe step, or at claim time). | Reverse order could leave a deleted account that nothing ever cancels or purges. |
| 2 | Claim is two conditional single-document writes: user `pending -> purging` (point of no return, recovery refused), then the workflow `$$NOW` lease. Recovery is user `pending -> none` (`purge_after > $$NOW`), then workflow delete. A crash between the two writes is repaired by the next run in both directions. | Mongo standalone, no transactions; the user document is the linearization point. |
| 3 | "Other web sessions die on their next request" is implemented as: `EnforceAccountDeletion` (web group, before `CheckUserSanction`) signs out any session lacking the **recovery stamp**; password/Google login to a pending account sets the stamp and redirects to `/account/recover`, which (plus logout) is the only reachable route. | Literal "log out and send to the recovery page" would loop with "login shows the recovery page". |
| 4 | Stripe cancels `active`, `trialing` **and `past_due`** subscriptions (past_due still bills retries). Per-subscription checkpoint `stripe_canceled[]` plus the `vietfeed-delete-cancel-<id>` key. The step also re-runs as `stripe_cancel_final` at purge, and `BillingGateway` re-cancels a subscription that turns billable for a pending account (late Checkout). | Closes the "Checkout completes after the request" money risk. |
| 5 | `StripeSdkClient::cancelSubscription` treats `resource_missing`, or a retrieve showing `canceled`, as success (idempotency keys expire after 24 h). | Retried step must converge. |
| 6 | Boost removal from Redis trending uses a new `Trending::retract(boostId, articleId)` (same Lua, version-2 removal under the live lock) instead of publishing `UNBOOSTED` events. | No event persists the user id in a stream, no dependency on legacy boost ids being UUIDv7. Idempotent (no-op once the version marker is gone). |
| 7 | Comments: `user_id` null, `body` empty string, `account_deleted: true`; the comment partial shows "Bình luận đã bị xóa". Replies by others untouched. | Task decision; `Comment::delete()` never used. |
| 8 | Cassandra anonymization only rewrites an `activity_by_article_day` row that exists and still names the user (CQL UPDATE would upsert a partial row). It runs as its own checkpointed step **before** the partition deletes. | Ordering rule from the task; avoids phantom rows. |
| 9 | Subscriptions rows are kept: `user_id` -> `deleted:<hmac(user id, APP_KEY)>`, `account_purged: true`, done **after** the user document is deleted. Webhooks for an owner that is `purging|purged` update the row by subscription id under the pseudonym. | Reconciliation after purge without PII. |
| 10 | `UserAuthorization::delete()` removed (it hard-deleted). The old staff path (`account_deleted` audit, Super Admin guard reservation) is gone; staff is refused with `AuthorizationConflict` (409) "Hãy gỡ quyền quản trị trước khi xóa tài khoản." after the existing `authorizeManagement` check. `UserAuthorization::write()` refuses a role change for a pending-deletion account (like `authorization_deleting`). | Control decision 3 (guard untouched) and avoids promoting a user a purge would then delete. |
| 11 | `Neo4jClient` lost `final` so the Feature suite can mock it (no Neo4j needed outside `tests/Integration`). The real Cypher is not exercised by a test (no User nodes exist). | Mockery cannot replace final classes. |
| 12 | Permanently banned users still cannot reach `DELETE /profile` (CheckUserSanction unchanged); `account.recover*` routes were added to its allow-list so a banned pending user can recover. Sanctions are never touched. | Inventory risk 7; documented, not changed. |
| 13 | `ACCOUNT_RECOVERY_DAYS` lives in new `config/accounts.php`; `.env.example` not touched. | Scope. |
| 14 | `users._id` is an ObjectId for Eloquent-created users while workflow `_id` and every `user_id` reference are strings. Raw user filters use `AccountLifecycle::userKey()`. | Found by the first test run. |

## File inventory

New: `app/Accounts/AccountLifecycle.php`, `app/Accounts/AccountPurge.php`, `app/Console/Commands/AccountsPurge.php`,
`app/Http/Controllers/AccountRecoveryController.php`, `app/Http/Middleware/EnforceAccountDeletion.php`, `config/accounts.php`,
`database/migrations/2026_11_23_000002_create_mongodb_account_deletion_indexes.php`, `resources/views/account/recover.blade.php`,
`tests/Feature/AccountLifecycleTest.php`, this report.

Changed: `app/ApiTokens/ApiTokenService.php` (pending user = account_unavailable), `app/Authorization/AdminRoutes.php`
(`users.recover`), `app/Authorization/UserAuthorization.php` (delete removed, promotion refused while pending),
`app/Billing/{BillingGateway,StripeClient,StripeSdkClient}.php`, `app/Http/Controllers/Admin/UserController.php`,
`app/Http/Controllers/Api/TokenController.php`, `app/Http/Controllers/Auth/{AuthenticatedSession,GoogleAuth,PasswordResetLink}Controller.php`,
`app/Http/Controllers/ProfileController.php`, `app/Http/Middleware/CheckUserSanction.php`, `app/Interaction/{InteractionStream,Trending}.php`,
`app/Models/User.php`, `app/ReadingPass/ReadingPassHistory.php`, `app/StoryProcessing/Neo4jClient.php` (final removed),
`bootstrap/app.php`, `routes/{web,console}.php`, `resources/views/{admin/users/edit,components/comment,profile/edit}.blade.php`,
`docs/database/nosql-local.md`, `tests/Doubles/FakeStripeClient.php` (cancelSubscription), `tests/TestCase.php` (wipe the new collection).

## Existing test edits (each assumed hard delete)

| Test | Edit | Reason |
|---|---|---|
| `NoSqlRuntimeTest::test_migrations_are_recorded...` | count 11 -> 12, assert the new file | required by the task |
| `AuthorizationTest::test_remaining_super_admin_cannot_be_demoted_or_deleted_by_either_path` | refusal audit count 3 -> 1; delete refusals expect the staff message; removed the final "delete after re-promotion removes the user and audits `account_deleted`" block | staff can no longer be deleted at all, and deletion no longer reserves the guard or audits |
| `ProfileTest::test_user_can_delete_their_account` | asserts `deletion.state = pending` instead of a missing user | deletion is a request |
| `ApiTokensTest::test_self_deletion_revokes_every_family`, `test_admin_deletion_revokes_every_family` | same assertion change | same |
| `ApiTokensTest::test_web_security_actions_complete_when_token_redis_is_down` | delete case asserts `pending` and expects `token_family_revoked` (not `account_unavailable`) | user document still exists, the epoch bump revokes |

## Step table

See `docs/database/nosql-local.md` ("Gate 9.7b") for the full store -> action -> idempotency table (13 steps). Summary of the argument:
every step is a filter-delete, a conditional update whose filter stops matching, a repeat of identical values, or guarded by its own
checkpoint (`steps.<name>`, `stripe_canceled[]`); checkpoints are written only under the live lease (`claim` + `lease_until > $$NOW`),
so a zombie worker aborts instead of advancing state.

## Tests (10, one file, no existing file added to)

request revokes web/API/reset/quota; staff refused + pending not promotable; recovery in window / refused purging or expired;
admin-origin not self-recoverable; purge of every store keeps evidence (+ dry-run); crash mid-steps idempotent (also with lost
checkpoints); Stripe down at request then retried with stable key; webhooks reconcile for pending and purged; late stream events
create no Cassandra row (with a live-user control); Google login to pending goes to recovery without restoring/verifying/linking.

## Verification

| Command | Exit | Result |
|---|---|---|
| `php -l` on every new/changed PHP file | 0 | no syntax errors |
| `vendor/bin/pint <changed files>` then `--test` | 0 / 0 | fixed `routes/web.php` import order only; then `passed` |
| `php artisan test --filter=AccountLifecycleTest` x3 | 0, 0, 0 | 10/10, 264 assertions each |
| `composer run test` | 0 | 591/591, 12074 assertions (581 on main + 10 new) |
| `php artisan test tests/Integration` | 0 | 36/36, 828 assertions |
| `git diff --check` | 0 | clean |

## Follow-ups / open risks

- Stream-consumer race: an event read just before the claim can write one Cassandra row after the Cassandra step (documented, manual delete).
- Cassandra scan starts at `users.created_at`; older imported history would be missed.
- Self-deletion by a permanently banned user stays impossible; delete + re-register after purge yields a clean account (sanctions stay on the old id). Policy decision for the owner.
- Neo4j purge step is mocked in tests; if Neo4j is down in production the purge keeps retrying (hourly) until it is up.
- `past_due` subscriptions are also canceled (extension of "active/trialing"); owner may narrow.
- Real-Stripe behaviour of `subscriptions->cancel` and the empty-string customer scrub is verified only against the fake (Stripe test mode check recommended before merge).
- Slice B items (NIT-3, 9.7a polish) untouched.

## Fix round 1

| Finding | Change | Where |
|---|---|---|
| Opus MAJOR-1 | `maintain()` treats a workflow as orphan only when the user is missing or has no `deletion`; a `purging` user is purged. Case added to the existing crash test (user flipped to `purging`, workflow old and pending without Stripe checkpoint). | `app/Accounts/AccountPurge.php` `maintain()`; `AccountLifecycleTest::test_purge_rerun_after_a_crash...` |
| Opus MINOR-1 / Hydrogen MAJOR-2 | `request()` refreshes the workflow (`$set` fresh fields + empty `steps`, `$unset` stripe_canceled/context/claim/lease/attempts/last_error*) unless it is purging/purged (dup-key from the upsert is swallowed; the user write then refuses). Early conflict if the model already shows a deletion. `recover()` reads `deletion.requested_at`, makes it part of the conditional write and deletes only the workflow with that `requested_at`. | `app/Accounts/AccountLifecycle.php` `request()`, `recover()` |
| Hydrogen MAJOR-1 | `apply()` projects under the pseudonym when the loaded user is `purging` or the row has `account_purged: true`; the live-user update filter adds `account_purged != true` (a lost race fails the receipt and Stripe retries). One assertion block added to the existing webhook test. | `app/Billing/BillingGateway.php` `apply()`; `AccountLifecycleTest::test_billing_webhooks...` |
| Opus MINOR-2 | Role-change write filter adds `deletion: {$exists: false}` (after the no-change early return, so profile edits are unaffected). | `app/Authorization/UserAuthorization.php` `write()` |
| Opus MINOR-3 | `recover(byAdmin: true)` only matches `origin = admin` (self-service only `self`); the edit-page button shows only for admin origin. | `AccountLifecycle::recover()`; `resources/views/admin/users/edit.blade.php` |
| Opus MINOR-4 | `renewLease()` (same conditional update as a checkpoint) per month bucket in `cassandra_anonymize` and `cassandra_delete`. | `AccountPurge.php` |
| Hydrogen MINOR-1 | `NewPasswordController::store` returns `INVALID_TOKEN` for a pending-deletion account before touching the user. | `app/Http/Controllers/Auth/NewPasswordController.php` |
| Hydrogen MINOR-2 | `stepStripeCustomer` scrubs the captured customer id plus every `stripe_customer_id` found on the user's `subscriptions` rows. | `AccountPurge.php` |
| Opus NIT-1, NIT-2; Hydrogen NIT-1 | `user_document` throws if the user still exists; staff-refusal log carries `workflow_id`; admin users index confirm copy now says lock now, purge after 30 days. | `AccountPurge.php`; `resources/views/admin/users/index.blade.php` |

Known retention, accepted and not changed (Hydrogen MINOR-2 b/c): Stripe subscription `metadata.user_id` stays at Stripe after cancellation, and
un-replayed entries in the `interactions:dead:v1` / `reading-pass:dead:history:v1` DLQs keep `{user_id, article_id}` until replayed or trimmed.
Skipped as instructed: Opus NIT-3, Hydrogen MINOR-3 (already documented above).

Verification (no new test methods; 10 tests, 266 assertions):

| Command | Exit | Result |
|---|---|---|
| `php -l` on changed files | clean | no syntax errors |
| `vendor/bin/pint <changed files>` then `--test` | 0 | passed |
| `php artisan test --filter=AccountLifecycleTest` x3 | 0, 0, 0 | 10/10 each |
| `composer run test` | 0 | 591/591, 12076 assertions |
| `php artisan test tests/Integration` | 0 | 36/36 |
| `git diff --check` | 0 | clean |

## Slice B

### Decision log

| Item | Decision and result |
|---|---|
| 8. Email change and reset | Admin and self-service changes atomically clear verification, set `password_reset_requires_verified_email`, and increment the API token epoch. Both reset endpoints use the same eligibility filter. The password write rechecks the current email, eligibility, and epoch in MongoDB. Unknown or ineligible addresses get the generic link status and the invalid-token response at redemption. Unverified registrations without a recorded change keep their existing reset access. |
| 8. Notice and Google | Admin changes send a Laravel mail notification to the previous address after the email write commits. Google email matching requires Google's `email_verified` claim. Linked Google IDs can still sign in with a different provider address. They can verify the account's current address only when it matches the verified claim. The verification write checks the address against MongoDB for signed links and Google. |
| 9. Audit UI and permissions | The responsive audit table resolves current actor and target names in one MongoDB query, retains IDs for unnamed accounts and system actors, and labels missing user records “Người dùng đã xóa”. The change column shows field values before → after. `User` reads and writes `permissions` as a native BSON array without the deprecated cast. |
| 9. Last Super Admin | A refused demotion shows “Không thể hạ quyền của Super Admin cuối cùng.” when the guard confirms that account is the last member. Other guard conflicts use the standard conflict message. |
| 9. Audit retry | Chose NIT-1 validation. Blank targets and timestamps before the Unix epoch or more than one day ahead are poison rows. This bounds the recent projection TTL while preserving historical replay and retry on Cassandra projection failures. |
| 9. Bootstrap candidate | The guard timestamps attempts and only clears the exact incomplete candidate after 60 minutes, outside guard repair. The first clear attempt timestamps a legacy marker without a timestamp. Clearing and staging `bootstrap_candidate_cleared` on `super-admins` share one guard update. Operators still confirm the abandoned process has stopped. |
| 9. Story state | The Story list and detail page use Vietnamese brief state labels. |

Extended the existing email-change, Google sign-in, audit projection, bootstrap
candidate, and Story lease tests. Updated existing copy assertions and supplied
the Google claim in the Reading Pass and Slice A recovery mocks. The recovery
test's assertions and the Slice A lifecycle implementation were preserved. The
array mailer captured the notice and reset mail in the email-change test.

### Verification

Suites ran sequentially against MongoDB and Cassandra `vietfeed_test`, Redis
DB15, and integration-created Neo4j nodes. A temporary empty `.env.testing`
and process-local test settings kept Laravel from loading the active `.env`.

| Command | Exit | Result |
|---|---:|---|
| `php -l` on 19 Slice B PHP and Blade files | 0 | All passed |
| `vendor/bin/pint <changed files>` | 0 | Passed |
| `vendor/bin/pint --test <changed files>` | 0 | Passed |
| `php artisan test --filter=AuthorizationTest` | 0 | 22/22, 1,681 assertions |
| `composer run test` | 0 | 591/591, 12,119 assertions |
| `php artisan test tests/Integration` | 0 | 36/36, 828 assertions |
| `git diff --check` | 0 | Clean |

The initial full run exited 1 because the Slice A Google recovery fixture
omitted Google's verification claim. The final full run passed after the
fixture supplied the claim.

### Follow-ups / open risks

- Notice delivery is synchronous and best effort. Transport failures are logged by user ID and exception type, with no durable retry.
- Address changes made before Slice B have no provenance marker. The reset gate applies to changes recorded after rollout.
- Existing Google sign-in by verified-email match still trusts proof of the current address. An admin who changes the email to a Google address they control could still use that path.
- The bootstrap fence uses the attempt start time. Clearing an old marker still relies on the operator confirming the process has stopped.

## Slice B fix round

- Google keeps the linked ID lookup first. A verified-email fallback refuses accounts awaiting address verification or linked to a different Google ID. The Vietnamese error appears on the login form. The link write checks the current email, token epoch, and absence of a Google ID and deletion marker. A MongoDB duplicate-key collision returns the same login error.
- Admin and self-service email changes clear `remember_token` in the email update and send the same neutral notice to the previous address after commit. Admin email validation now requires lowercase.
- The existing email-change and Google sign-in tests cover both fallback refusals, the rendered login error, the stored remember-token value, and the old-address notices. The full suite includes the existing pending-deletion recovery test.

The suites ran sequentially on the isolated stores documented above.

| Command | Exit | Result |
|---|---:|---|
| `php -l` on six changed PHP files | 0 | All passed |
| `vendor/bin/pint <changed files>` | 0 | Passed |
| `vendor/bin/pint --test <changed files>` | 0 | Passed |
| `php artisan test --filter=AuthorizationTest` | 0 | 22/22, 1,699 assertions |
| `composer run test` | 0 | 591/591, 12,144 assertions |
| `php artisan test tests/Integration` | 0 | 36/36, 828 assertions |
| `git diff --check` | 0 | Clean |

The two preliminary authorization and full suite runs exited 1 while the
test used Laravel's string-valued remember-token accessor and omitted the
session cookie on the login redirect. The final test checks the stored null
and follows the callback with its session cookie.

## Control final gate and browser acceptance (2026-11-24)

Delegation: Gemini 4 Neon (read-only inventory) → Claude Sonnet 5.5 (Slice A) → Claude Opus 5.5 + Gemini 4 Hydrogen (parallel
reviews, `REVIEW-1-OPUS.md`, `REVIEW-1-HYDROGEN.md`) → Sonnet fix round → Opus `REVIEW-2-OPUS.md` APPROVE → GPT 6.2 Sol (Slice B)
→ Opus + Hydrogen (`REVIEW-3-*-SLICE-B.md`; both found the Google email-match takeover) → Sol fix round → Opus
`REVIEW-4-OPUS-SLICE-B.md` APPROVE → GPT 6.1 Terra browser acceptance → control fix (below) → Opus APPROVE.

| Command | Result |
| --- | --- |
| `composer run test` | exit 0, 591/591 (12 147 assertions) |
| `php artisan test tests/Integration` | exit 0, 36/36 |
| `php artisan test --filter=AccountLifecycleTest` ×3 | exit 0 ×3 |
| `php artisan test --filter='AccountLifecycleTest\|Comment\|Moderation'` after the view fix | exit 0, 102/102 |
| `vendor/bin/pint --test` on changed/new PHP files | exit 0 |
| `git diff --check` | exit 0 |

### Browser acceptance (Terra, Playwright, 390 px, throwaway Mongo `vietfeed_live_check`, Cassandra `vietfeed_live_check`, Redis DB14)

PASS: profile deletion section; self-deletion → logged out; login → `/account/recover`; recover → active; Super Admin schedules
deletion of a plain user (pending state, purge date, "Khôi phục"); that user's login shows "Tài khoản đang chờ xóa" without
self-recovery and `/profile` redirects to recovery; admin recovery; audit page with names and before → after; Vietnamese Story
state labels. `accounts:purge` on a backdated request: first run stopped at `cassandra_anonymize` (throwaway keyspace lacked the
interaction tables — environment, fixed with `interactions schema` / `reading-pass schema`), the re-run resumed from the failed
step and finished (`purged=1 failed=0`), demonstrating checkpoint resume. Article page then showed the anonymized comment
("Người dùng đã xóa", "Bình luận đã bị xóa") with the other user's reply intact. (Fixture articles have no category/source, so the
article was attached to a throwaway category/source for the screenshot.)

**Defect found and fixed by control:** the anonymized comment showed the "Của bạn" badge and owner edit/delete controls to a guest
(`auth()->id() === $comment->user_id` with both null). Server side was safe (auth routes + string id checks → 403). Fix: null
guard in `resources/views/components/comment.blade.php` (3 places) and `comment-kebab.blade.php`; 3 assertions added to
`test_purge_removes_or_anonymizes_every_store_and_keeps_evidence` (fail on the old view, pass on the fix). Opus: APPROVE.

Cleanup: server stopped by PID; Mongo `vietfeed_live_check` dropped (name guard), Redis DB14 flushed (`getDbNum()===14`),
Cassandra keyspace dropped. Dev intact: 3295 articles, 4 users, 13 comments.

### Follow-ups (control)

- Anonymized comments still offer "Báo cáo"; reports store `comment_user_id = ''` (sanction safely refused). Hide the action and
  refuse reports on `account_deleted` comments (Opus REVIEW-3 NIT-2).
- Pre-existing: never-verified account without `google_id` is linked/verified on a verified-email Google match (Opus NIT-2).
- Pending-deletion account with a different `google_id` gets the login error instead of recovery via Google (password works).
- Two simultaneous deletion requests leave workflow fields from the loser (benign; Opus REVIEW-2 NIT-1).
- `favorite_category_ids` `array` cast deprecation; durable retry for the email-change notice.
- Audit table is cramped at 390 px (horizontal scroll works); admin nav labels "Dashboard"/"Health" and raw permission keys are
  English.
- Owner policy: permanently banned users cannot self-delete; Stripe test-mode smoke test of `cancelSubscription` and the customer
  scrub before relying on it in a demo.
- Dev after merge: `php artisan migrate --step`, `interactions schema` / `reading-pass schema` already provisioned on dev (verify),
  then the hourly `accounts:purge` schedule runs with the scheduler.
