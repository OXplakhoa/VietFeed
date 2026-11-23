<!-- Read-only review by Gemini 4 Hydrogen (agy, plan mode), 2026-11-23. -->
# Security & Privacy Review — Gate 9.7b Slice A (Account Deletion)

**Verdict: Conditional Approve (0 BLOCKER, 2 MAJOR, 4 MINOR)**  
Core revocation, recovery gating, lease fencing, and multi-store purge work as designed, with two real race/crash state bugs (billing webhook un-pseudonymization after purge; stale workflow state after recovery crash) and minor residual PII/hardening gaps.

---

## 1. `INVENTORY.md` Coverage Check

| Row | Purge / Handling (`file:line`) | Post-purge survivors / notes |
|---|---|---|
| Mongo `users` | Deleted at [AccountPurge.php:337-341](app/Accounts/AccountPurge.php#L337-L341) | None (`deletion.state = purging` guard) |
| Mongo `users.favorite_category_ids` | Deleted with `users` ([AccountPurge.php:339](app/Accounts/AccountPurge.php#L339)) | None |
| Mongo `authorization_control` | Staff blocked at [AccountLifecycle.php:71-73](app/Accounts/AccountLifecycle.php#L71-L73) | `bootstrap_user_id` retained if former Super Admin demoted & purged (ID only) |
| Mongo `comments` | Anonymized in place ([AccountPurge.php:267-271](app/Accounts/AccountPurge.php#L267-L271)) | `user_id: null`, `body: ''`, `account_deleted: true`; replies untouched |
| Mongo `bookmarks` | Deleted ([AccountPurge.php:247-250](app/Accounts/AccountPurge.php#L247-L250)) | None |
| Mongo `boosts` | Retracted + deleted ([AccountPurge.php:252-260](app/Accounts/AccountPurge.php#L252-L260)) | None |
| Mongo `article_unlocks` | Deleted by `user_id`/`subject` ([AccountPurge.php:262-265](app/Accounts/AccountPurge.php#L262-L265)) | None |
| Mongo `subscriptions` | Pseudonymized ([AccountPurge.php:343-348](app/Accounts/AccountPurge.php#L343-L348)) | `deleted:<hmac>`, `stripe_customer_id`; **see MAJOR-1** |
| Mongo `reports` (+ `_duplicates`) | Intentionally kept (`TASK.md:60`) | `reporter_id`, `comment_user_id`, `comment_body` snapshot |
| Mongo `moderation_cases` | Intentionally kept (`TASK.md:60`) | `comment_user_id`, `decisions[]`, `events[]` |
| Mongo `sanctions` (incl. appeals) | Intentionally kept (`TASK.md:60`) | `user_id`, `reason`, `appeal_message` |
| Mongo `authorization_audit_pending` | Outbox transferred ([AccountPurge.php:328-335](app/Accounts/AccountPurge.php#L328-L335)) | Intentionally kept (`target`/`actor` IDs, no email/name) |
| Mongo `story_briefs` (+ `stories.brief`) | Intentionally kept (`TASK.md:60`) | `reviewed_by` ID, `review_note` |
| Cassandra `activity_by_user_month` | Partition delete from `created_at` ([AccountPurge.php:296-304](app/Accounts/AccountPurge.php#L296-L304)) | Pre-`created_at` backfill or stream-race row (**MINOR-4**) |
| Cassandra `activity_by_article_day` | `user_id -> 'deleted'` ([AccountPurge.php:279-294](app/Accounts/AccountPurge.php#L279-L294)) | Pre-`created_at` backfill or stream-race row (**MINOR-4**) |
| Cassandra `unlocks_by_user_month` | Partition delete from `created_at` ([AccountPurge.php:296-304](app/Accounts/AccountPurge.php#L296-L304)) | Pre-`created_at` backfill or stream-race row (**MINOR-4**) |
| Cassandra `authorization_audit_by_*` | Intentionally kept (`TASK.md:61`) | `target_id`/`actor_id` (`_by_day` has 30d TTL) |
| Cassandra `moderation_events_by_case` | Intentionally kept (`TASK.md:61`) | `actor_id`, `note` |
| Neo4j `User` nodes / edges | `DETACH DELETE` ([AccountPurge.php:306-310](app/Accounts/AccountPurge.php#L306-L310)) | None (not verified against live Neo4j; mocked in tests) |
| Redis API tokens | Revoked on request + [AccountPurge.php:275,340](app/Accounts/AccountPurge.php#L275) | `access`/`refresh` keys carry `uid`+`family` until TTL+60s ([tokens.lua:23](app/ApiTokens/tokens.lua#L23)) |
| Redis web sessions | Current invalidated; others killed by [EnforceAccountDeletion.php:36-42](app/Http/Middleware/EnforceAccountDeletion.php#L36-L42) | Session payload expires by 120m TTL; dead after user doc deleted |
| Redis password reset tokens | Best-effort deleted on request ([AccountLifecycle.php:95](app/Accounts/AccountLifecycle.php#L95)) | 60m TTL if Redis failed at request (**MINOR-2**) |
| Redis Reading Pass quota | Deleted on request + [AccountPurge.php:276](app/Accounts/AccountPurge.php#L276) | None (10s lock expires) |
| Redis trending & Streams | `Trending::retract` ([AccountPurge.php:257](app/Accounts/AccountPurge.php#L257)); consumers skip `purging\|purged` | DLQs (`interactions:dead:v1`, `reading-pass:dead:history:v1`) keep `user_id` (**MINOR-3**) |
| Redis rate limiters | 60s TTL (naturally expire) | None after 60s |
| Stripe `Customer` & `Subscription` | Subs canceled ([AccountLifecycle.php:156-175](app/Accounts/AccountLifecycle.php#L156-L175)); Customer scrubbed ([AccountPurge.php:312-326](app/Accounts/AccountPurge.php#L312-L326)) | Sub `metadata.user_id` (**MINOR-3**); real Stripe API behavior not verified |

## 2. Takeover Vectors (Pending & Purged)

- **Email / `google_id` reservation:** Unique indexes remain on `users` until `stepUserDocument` deletes the user; re-registration after purge creates a fresh `_id` with no access to old data.
- **Google OAuth & API refresh & remember-me:** [GoogleAuthController.php:76-82](app/Http/Controllers/Auth/GoogleAuthController.php#L76-L82) routes pending users only to `/account/recover` without linking `google_id` or verifying email. [ApiTokenService.php:85-87](app/ApiTokens/ApiTokenService.php#L85-L87) rejects refresh/access for pending users; `tokens.lua:71-82` does not recreate a deleted `family` key on a concurrent `rotate`. `remember_token` is cleared at [AccountLifecycle.php:87](app/Accounts/AccountLifecycle.php#L87), and a `remember` cookie obtained during recovery login lacks `SESSION_KEY` on a new session so `EnforceAccountDeletion` signs it out.
- **Admin-origin recovery & password reset:** [AccountLifecycle.php:108-110](app/Accounts/AccountLifecycle.php#L108-L110) enforces `deletion.origin = 'self'` for non-admin recovery. [PasswordResetLinkController.php:35-37](app/Http/Controllers/Auth/PasswordResetLinkController.php#L35-L37) suppresses reset mail for pending users, **but pre-existing reset tokens are still accepted (see MINOR-2)**.

## 3. Crash / Lease / Concurrency Audit & 4. PII in Logs, Output, Workflows, Stripe

Logs ([AccountLifecycle.php:198](app/Accounts/AccountLifecycle.php#L198), [AccountPurge.php:87,122,176](app/Accounts/AccountPurge.php#L87)), CLI output ([AccountsPurge.php:35-38](app/Console/Commands/AccountsPurge.php#L35-L38)), and finalized `account_deletion_workflows` ([AccountPurge.php:229-233](app/Accounts/AccountPurge.php#L229-L233)) retain no PII. Issues found:

---

### Findings

#### [MAJOR-1] Billing webhook race can un-pseudonymize `subscriptions.user_id` after purge
- **Location:** [BillingGateway.php:257-294](app/Billing/BillingGateway.php#L257-L294), [AccountPurge.php:337-348](app/Accounts/AccountPurge.php#L337-L348)
- **Scenario:** A Stripe webhook enters `BillingGateway::apply()`, loads `$user = User::find($userId)` (`:258`, while `deletion.state = purging`), and pauses during `retrieveSubscription()`. Meanwhile `AccountPurge` finishes `stepUserDocument`, `stepSubscriptions` (writing `deleted:<hmac>` and `account_purged: true`), and `finish()` (`state = purged`). `apply()` then resumes at `:280-294` and writes `'user_id' => (string) $user->id`, reverting the pseudonym permanently because the workflow is already `purged`.
- **Fix:** In `BillingGateway::apply()`, if `$user` has `($user->deletion['state'] ?? null) === AccountLifecycle::PURGING` or `subscriptions` already has `account_purged: true` under `$fence`, project under `AccountLifecycle::pseudonym((string) $user->id)` with `account_purged: true`; also guard the live-user update filter with `'account_purged' => ['$ne' => true]`.

#### [MAJOR-2] Crash in `recover()` leaves a stale workflow that disables Stripe retry & skews `purge_after` on a later deletion
- **Location:** [AccountLifecycle.php:83,105-117](app/Accounts/AccountLifecycle.php#L83), [AccountPurge.php:101](app/Accounts/AccountPurge.php#L101)
- **Scenario:** User recovers (`users.deletion` unset at `:111`), then the process crashes before `workflows()->deleteOne()` (`:116`). If the user re-subscribes and requests deletion again before `accounts:purge` reaches `claim()` to clean the orphan, `request()` runs `$setOnInsert` (`:83`), which is a **no-op** on the existing workflow doc. `steps.stripe_cancel` and `stripe_canceled` from the first cycle remain set, so if Stripe is down on the second `request()`, `maintain()` (`AccountPurge.php:101`) never retries canceling the new subscription until `purge_after` (30d later), and the stale `purge_after` causes hourly no-op `due` scans.
- **Fix:** In `AccountLifecycle::request()` (`:83`), when updating `workflows` for a user not currently `purging`/`purged`, reset `state`, `requested_at`, `purge_after`, `origin`, `requested_by`, `steps`, and `$unset` stale `stripe_canceled`, `context`, `claim`, `lease_until`, `attempts`, `last_error*` (conditional on `state` not `purging`/`purged`, or clean any non-pending leftover only after checking `users.deletion` does not exist).

#### [MINOR-1] `NewPasswordController::store` does not block pending-deletion accounts with an existing reset token
- **Location:** [AccountLifecycle.php:95](app/Accounts/AccountLifecycle.php#L95), [NewPasswordController.php:43-53](app/Http/Controllers/Auth/NewPasswordController.php#L43-L53)
- **Scenario:** If Redis was down at deletion request (so `bestEffort('password reset token')` skipped deletion) or the token existed prior to an `admin`-origin deletion, submitting `POST /reset-password` within 60 minutes mutates `password` and `remember_token` on the pending account (`EnforceAccountDeletion` still restricts web access to `/account/recover` and blocks `admin`-origin recovery).
- **Fix:** Reject pending-deletion users inside `NewPasswordController::store()` (returning `Password::INVALID_TOKEN` without modifying the user) and optionally clear the cache reset key in `AccountPurge::stepRedis()` if `email` is captured before `stepUserDocument`.

#### [MINOR-2] Stripe Subscription `metadata.user_id` and Redis Stream DLQs retain `user_id` after purge; late-projected Customer ID missed
- **Location:** [BillingGateway.php:55](app/Billing/BillingGateway.php#L55), [AccountPurge.php:208,273-277,312-326](app/Accounts/AccountPurge.php#L312-L326), [StreamConsumer.php:177](app/Processing/StreamConsumer.php#L177)
- **Scenario:** (a) Stripe Subscriptions retain `metadata.user_id` after `cancelSubscription`; (b) any un-replayed events for the user in `interactions:dead:v1` or `reading-pass:dead:history:v1` retain `{user_id, article_id}`; (c) if `users.stripe_customer_id` was `null` at `claim()` and set only by a late webhook afterward, `stepStripeCustomer` skips scrubbing it.
- **Fix:** Scrub `stripe_customer_id` from `subscriptions` as fallback in `stepStripeCustomer`; clear/pseudonymize Stripe Subscription `metadata.user_id` after `stepSubscriptions` (if policy requires); purge matching `user_id` entries from stream DLQs in `stepRedis` (or document DLQ retention).

#### [MINOR-3] Documented gaps: stream-consumer race, pre-`created_at` Cassandra history, unverified live Stripe/Neo4j
- **Location:** [InteractionStream.php:45](app/Interaction/InteractionStream.php#L45), [ReadingPassHistory.php:100](app/ReadingPass/ReadingPassHistory.php#L100), [AccountPurge.php:207,309](app/Accounts/AccountPurge.php#L207), [StripeSdkClient.php:46-64](app/Billing/StripeSdkClient.php#L46-L64)
- **Scenario:** Already documented in [GATE-REPORT-9.7b.md:81-86](.agent/tasks/account-lifecycle/GATE-REPORT-9.7b.md#L81-L86): a consumer passing `isPurging()` right before `claim()` can write one Cassandra row after `stepCassandraDelete`; `months()` ignores rows older than `users.created_at`; live Stripe SDK empty-string Customer scrub and live Neo4j Cypher are not verified against real services.
- **Fix:** Re-check `isPurging()` / sweep once or keep operational runbook; pass `1970-01` or min-month if backfills predate `created_at`; smoke-test `StripeSdkClient::updateCustomer` and `cancelSubscription` in Stripe test mode before merge.

#### [NIT-1] Admin user list delete confirmation still says "sẽ bị xóa vĩnh viễn"
- **Location:** [index.blade.php:116](resources/views/admin/users/index.blade.php#L116)
- **Fix:** Update confirmation copy to match [edit.blade.php:175](resources/views/admin/users/edit.blade.php#L175) (scheduled lock + 30-day purge).
