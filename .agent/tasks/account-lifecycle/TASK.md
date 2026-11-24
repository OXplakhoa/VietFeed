# Gate 9.7b — Account lifecycle: deletion, 30-day recovery, purge

Canonical sources: `docs/specs/vietfeed-nosql-storylens.md` §7 story 18 ("access revoked immediately and a 30-day recovery period
before purge"), Account Lifecycle module ("immediate revoke, Stripe cancellation, 30-day retention, recovery, idempotent purge,
Neo4j cleanup, and Cassandra anonymization"), §12.5 items 1–4, acceptance item 12; `docs/architecture/database-feature-map.md`
row "Account deletion" (MongoDB workflow state `account_deletion_workflows`; no distributed transaction across four stores).

One gate, one branch, one PR: branch `account/lifecycle`, base `main` `7ce33c8`. **Control stops before merging (owner approval).**
Delivered as two sequential slices on the same branch (one writer at a time):

- **Slice A (Claude Sonnet 5.5):** outcomes 1–7 (lifecycle core, Stripe cancel, purge, tests).
- **Slice B (GPT 6.2 Sol):** outcomes 8–9 (email-change takeover, 9.7a polish), after slice A is reviewed.

## Recon

`INVENTORY.md` in this folder (Gemini 4 Neon, read-only, verified by control) lists every place user data lives, with file:line,
and 8 risks. Key facts: deletion today hard-deletes the Mongo user only (`UserAuthorization::delete`), orphaning Stripe
subscription (still billing, `proUserIds()` still counts it), comments, bookmarks, boosts (+trending score), article_unlocks,
Cassandra `activity_by_user_month` / `activity_by_article_day.user_id` / `unlocks_by_user_month`. Neo4j has no User nodes today.
`StripeClient` has no cancel method. `Comment::delete()` cascades to other users' replies (never use it in purge). Web sessions
have no per-user index in Redis.

## Control decisions (owner may override at PR review)

1. **States.** User document gets `deletion = {state: pending|purging, requested_at, purge_after (= requested_at + 30 days,
   config `ACCOUNT_RECOVERY_DAYS` default 30), requested_by, origin: self|admin}`; durable workflow + step checkpoints live in Mongo
   `account_deletion_workflows` (`_id` = user id). After purge the user document is gone and the workflow keeps only
   `{_id, state: purged, requested_at, purged_at, steps{name: done_at}}` — no email, name or other PII.
2. **Request (self-service, password confirmed):** one conditional single-document write (only from "no deletion"), then
   immediate revocation: API token families (`revokeAllForUser`), `remember_token` cleared, password-reset token deleted, Reading Pass
   quota forgotten, current session invalidated. Other web sessions die on their next request: a middleware (same pattern as
   `CheckUserSanction`) logs out any user whose `deletion` is set and sends them to the recovery page. The request must not fail
   because Stripe/Cassandra/Redis is down (step stays pending, worker retries).
3. **Staff:** admins and Super Admins cannot request deletion while staff ("Hãy gỡ quyền quản trị trước"); a Super Admin demotes
   them first (existing invariants apply). This keeps the Super Admin guard untouched by this gate.
4. **Admin-initiated deletion** of a plain user (`users.manage`) uses the same lifecycle with `origin: admin`; the user cannot
   self-recover it, only an admin with `users.manage` can ("Khôi phục" on the user edit page). Self-service deletion is recoverable
   by the user.
5. **Recovery:** logging in (password or Google) to a pending-self-deletion account shows `/account/recover` (explain date of purge,
   button "Khôi phục tài khoản"); restoring is a conditional write `pending → none` only while `purge_after > now` and state is
   `pending`. Email and `google_id` stay reserved during the 30 days (unique indexes unchanged); password reset links are not sent
   for pending accounts (same generic response). Recovery does NOT reactivate Stripe (documented; the user resubscribes).
6. **Stripe:** on request, cancel every active/trialing subscription of the user immediately via a new
   `StripeClient::cancelSubscription(id, idempotencyKey)` (key `vietfeed-delete-cancel-<sub_id>`), as a workflow step retried by
   the worker on failure; `BillingGateway::apply()` must keep reconciling webhooks for pending users and, after purge, update the
   `subscriptions` document by subscription id instead of dropping it as `unknown_user`. At purge the Stripe Customer is kept (invoice
   history) but scrubbed (`email`/`name` removed via `updateCustomer`), and `subscriptions.user_id` is replaced by a pseudonym.
7. **Purge worker** `php artisan accounts:purge [--limit=] [--dry-run]`, scheduled hourly (`withoutOverlapping`,
   `runInBackground`): claims workflows with `purge_after <= now` by a `$$NOW` lease (`pending → purging`; recovery refused once
   `purging`), runs idempotent checkpointed steps, each safe to re-run after a crash:
   - Mongo: delete `bookmarks`, `boosts` (and remove their trending contribution; justify the method), `article_unlocks`; comments
     anonymized in place (`user_id` null, body removed, flag so the UI shows "Bình luận đã bị xóa" / "Người dùng đã xóa"; replies by
     other users untouched).
   - Cassandra: anonymize `activity_by_article_day.user_id` using keys read from `activity_by_user_month` first, then delete the
     user's `activity_by_user_month` and `unlocks_by_user_month` partitions (month range from `created_at` to now).
   - Redis: token families cleanup, Reading Pass keys. Neo4j: idempotent `MATCH (u:User {uuid:$id}) DETACH DELETE u` (no-op today).
   - Stripe customer scrub; finally delete the user document and mark the workflow `purged`.
   - Late events: interaction / reading-pass stream consumers must skip events for users whose workflow is `purging|purged`, so
     Cassandra rows are not recreated after purge.
   - KEEP (evidence, §12.5 item 4): `reports`, `moderation_cases`, `sanctions` (+appeals), `story_briefs.reviewed_by`,
     authorization audit (Mongo outbox + Cassandra). A sanctioned user's deletion never removes sanctions.

## Required outcomes

1. Lifecycle module (`App\Accounts\...`): request, recover, admin request/recover, purge with steps as above; all writes conditional
   single-document (Mongo standalone, no transactions); a crash between steps is recoverable by re-running the worker.
2. UI: profile "Xóa tài khoản" explains 30-day recovery; `/account/recover` page; admin user edit shows deletion state, purge date,
   "Khôi phục" (admin origin) and replaces the hard delete button with "Lên lịch xóa".
3. Middleware revocation on next request for every route (web + API bearer already via epoch).
4. Stripe cancel step + webhook reconciliation for pending/purged users (Stripe test mode only; tests use the fake client).
5. `accounts:purge` + schedule + `--dry-run` that prints counts per step, no PII.
6. Migration `*_create_mongodb_account_deletion_indexes` (workflow `state`+`purge_after`; whatever the queries need).
7. **Tests — "vừa đủ" (owner rule):** at most ~10 focused tests in one new file `tests/Feature/AccountLifecycleTest.php`, one per
   real risk: request revokes web session + API tokens + reset token; staff refused; recovery within window restores, after
   `purging` refused; admin-origin not self-recoverable; purge removes/anonymizes every store in the inventory and keeps evidence
   (one test, real isolated Mongo/Cassandra/Redis); purge re-run after a simulated crash mid-steps is idempotent; Stripe down at
   request → step retried by worker; late stream event after purge creates no Cassandra row; Google login to pending account goes to
   recovery, not auto-restore. Update existing tests only where they assumed hard delete (list them with reasons).
8. **Slice B:** NIT-3 email-change takeover (REVIEW-2 in `.agent/tasks/rbac/`): after an admin changes a user's email, password
   reset to the new address is refused until that address is verified, and a notice is mailed to the old address; Google login by
   `google_id` must not mark an unverified changed email as verified. ≤ 3 tests.
9. **Slice B:** 9.7a polish from `.agent/tasks/rbac/GATE-REPORT-9.7a.md` "Follow-ups added by control": audit page shows actor/
   target names (fallback id) and a readable before→after diff; specific copy for the last-Super-Admin refusal; drop the
   `permissions` `array` cast deprecation; REVIEW-3 NIT-1 (only connection/timeout = outage) and NIT-2 (`--clear-candidate`
   staleness fence + guard audit event); admin Story page shows the Vietnamese state label. ≤ 3 tests (extend existing ones first).
10. Docs: `docs/database/nosql-local.md` (states, workflow fields, steps, commands, what is kept and why), `GATE-REPORT-9.7b.md`
    here (decision log, verification commands + exit codes, follow-ups).

## Hard rules / isolation

Never touch dev data (Mongo `vietfeed`, Redis DB0/1/2, Cassandra `vietfeed`, Neo4j nodes not created by the test); no FLUSHALL, no
migrate/seed/new commands against dev, no `migrate:fresh`/`db:wipe`, no container starts/restarts. No real Stripe or network calls.
Never read, print or edit the active `.env`. Keep the forced env lines in `phpunit.xml`. Sequential suites. Never edit existing
migrations. No new Composer/npm dependencies. Owner WIP untouched: `CLAUDE.md`, `database/seeders/SourceSeeder.php`,
`.agent/tasks/{infra-cassandra-spike,mongo-foundation,neo4j-foundation,redis-cutover,claude-code-handoff}/`, `.agent/handoff/`,
`.agent/migration-pc/`, `.agent/control/`, `.pi/`, `docs/agents/`, `pi-warden.md`. Do not commit; control commits.

## Non-goals

GDPR export, API endpoint scopes, Neo4j user graph features, redesign beyond the listed screens.

## Stop conditions

Product change beyond the decisions above; owner WIP edit; need to change the active `.env`; failure not fixable in scope; container
restart or OOM; any need for a new dependency.
