# Gate 9.7a RBAC — Independent review 2 (fix round 1)

Reviewer: Opus 5.5 subagent, 2026-11-23. Scope: the full working tree vs `main` (bcdcbba) after "Fix round 1" in
`GATE-REPORT-9.7a.md`. Docker is down, so this review is read-only and **no tests were run**. Static checks only: `php -l` on every
changed/new PHP file passed, and I inspected imports, method signatures, callers of renamed methods, and command auto-discovery.

## REVIEW-1 findings: status

| REVIEW-1 | Status | Evidence |
|---|---|---|
| MAJOR-1 staff ban by Admin | **Fixed** | See the entry-point analysis below. Test: `test_staff_bans_are_authorized_on_direct_standalone_report_and_appeal_paths` covers the service call, direct store, report store (before the claim), Super Admin success, appeal deny refused, appeal approve allowed, and audit reasons. |
| MINOR-1 403s not audited | **Fixed** | `UserAuthorization::authorizeManagement` records `privilege_change_refused` on Gate denial. `AdminMiddleware` covers the cases the route `can:` rejects before the controller runs. `recordBestEffort` keeps the 403 when Cassandra or Mongo audit fails. Test: `test_refusal_is_queued_when_cassandra_is_unavailable_and_route_gate_still_returns_403`. |
| MINOR-2 bootstrap rebuild race | **Fixed** | See the step-by-step analysis below. Test: `test_bootstrap_completion_cannot_reinsert_a_concurrently_demoted_super_admin`. |
| MINOR-3 24 h overlap lock | **Fixed** | `withoutOverlapping(5)`. |
| MINOR-4 flaky ordering | **Fixed** | Order-sensitive assertions now use `assertContains`, sets, or canonical comparison. |
| MINOR-5 marker tooling | **Fixed** | `rbac:repair`. See the safety analysis below. Tests cover dry-run, staleness, a committed vs uncommitted ban, and maintenance mode. |
| NIT-1 super→admin tokens | **Fixed** | `$roleReduced` forces revocation. Test: `test_demotion_with_all_permissions_still_revokes_tokens...`. |
| NIT-2 bare 409 | **Fixed** | `AuthorizationConflict` (409 `HttpException`) renders `back()->with('error')` for web requests and keeps JSON as 409. `withInput` excludes the password. Tested. |
| NIT-3 self-demotion redirect | **Fixed** | Uses the in-memory (pre-write) model, so `isSuperAdmin()` is still true at the check. Tested. |
| NIT-4 seeder without command | **Fixed** | Falls back to `$this->command?->call(...) ?? Artisan::call(...)`. |
| NIT-5 head-of-line / index | **Fixed** | Per-event attempts and dead-lettering; a migration adds a sparse `authorization_audit_pending.0` index and a `(dead_at, attempts, _id)` index. Tested with a poison event. |
| NIT-6 email takeover | **Partially fixed** (as suggested) | See NIT-3 below. |
| NIT-7 checkbox restore | **Fixed** | Hidden `permissions_form` input. Tested with a DOM parse. |

## MAJOR-1: every sanction entry point

The only sanction writer is `ModerationService::createSanction`. A grep finds no other `new Sanction` / `Sanction::create` in
`app/`, seeders or commands. The only caller of `issueSanction` is `SanctionController::store`, which serves both the standalone and
the report path. `ReportController::update` only dismisses cases.

- `issueSanction`: `authorizeBan(fresh actor, fresh target)` runs before the case claim, so a refused report ban leaves the case
  `pending` and the comment visible.
- `createSanction` → `beginBan`: re-authorizes with a fresh actor and target, then writes a CAS marker and a version bump for **all**
  temporary and permanent bans. A concurrent user→admin promotion fails on the version CAS, or on the `ban_pending` check in `write()`.
  A ban racing a promotion fails on its CAS.
- `decideAppeal` deny on an effective ban: `authorizeBan`. Approve and revoke stay open, which is correct because lifting a ban never
  disables staff.
- A mute of staff is still allowed. It is harmless because a mute does not block `/admin`.

Conclusion: no remaining path lets a non-Super-Admin ban staff.

## MINOR-2: bootstrap race, step by step

1. `beginBootstrap` upserts the guard and sets `bootstrap_candidate_id`. It succeeds only if the bootstrap is not finished, no repair
   is running, and there is no other candidate.
2. `bootstrap(initial)` runs `write()` in promoting mode: it reads the generation, commits the user, then `add(id, generation)`.
3. The legacy grant now touches only `role=admin` documents with a null `authorization_version`, so a rerun or concurrent work is
   preserved.
4. `finishBootstrap` only `$set`s `bootstrap_user_id`, guarded by `user_ids.0` existing. It **never writes `user_ids`**.

Membership is therefore maintained only by `add` (after commit) and the conditional `$pull`. The invariant "guard ⊆ committed Super
Admins" holds globally. The REVIEW-1 scenario (I demotes X during bootstrap) now ends with guard {I}. The test reproduces exactly
this.

Retry of a partial run: if the user commit landed and `add` did not, the rerun gets `write()` returning false, the guard is empty, and
`finishBootstrap` fails. The command then tells the operator to run `rbac:repair --guard` and rerun. That path is recoverable and
safe.

## `rbac:repair` safety

- **Dry run is the default.** Without `--force`, no token is taken, no fence runs, and no update is made.
- **Guard repair:**
  - `beginRepair` sets `repair_token` and a new `generation`. While the token is set, `reserveRemoval` and `add` both refuse because
    they filter on `repair_token: null`, and `promotionGeneration` throws.
  - `$before` is read after the token is taken, so no removal can be in flight for a current member.
  - A Super Admin missing from the guard is version-fenced by CAS. An earlier removal that already pulled its ID now fails its user
    CAS and stays a Super Admin, and repair re-adds it (tested).
  - A promotion that read the old generation can never register (tested).
  - `$eligible === []` is refused, and `finishRepair` refuses an empty list as well. **Zero members is impossible.**
  - The result is always a subset of committed, unbanned Super Admins.
- **Marker repair:**
  - Only markers older than 60 minutes are cleared.
  - The CAS filter includes the snapshot and the exact marker values.
  - An uncommitted ban marker requires maintenance mode. A committed ban marker can be cleared because the sanction itself still
    blocks promotion.
  - The 60-minute floor plus the CAS means it cannot clear a live in-flight marker.
  - Clearing `authorization_deleting` leaves the account as a plain user, which is safe.

## Other checks (no finding)

- **Token revocation:** any role reduction, effective-permission loss, or email change bumps the epoch in the same CAS write as the
  change. A name-only edit does not (tested).
- **Profile update:** now goes through `UserAuthorization::updateProfile`, using raw `updateOne` with `updated_at` set manually. The
  only `User` model hooks concern password changes and `deleted`, so nothing is lost. The CAS includes `email` and
  `authorization_version`. A concurrent ban (version bump) makes the profile save 409 with a flash, which is benign.
- **MustVerifyEmail:** the email change sets `email_verified_at` to null, exactly as before for self-service. The `verified`
  middleware and the resend flow are unchanged.
- **Runtime sanity:**
  - All new classes, imports and methods resolve. Callers of the old `beginPermanentBan` / `finishPermanentBan` names were all
    updated.
  - `Sanction::isEffectiveBan` is public.
  - `AuthorizationAudit::event` accepts `User|string`.
  - The test mocks the `Illuminate\Contracts\Foundation\MaintenanceMode` contract, which is what `isDownForMaintenance()` resolves.
  - `AdminMiddleware` reads the bound `{user}`. `admin` runs after `SubstituteBindings` because only `auth` is moved earlier by
    middleware priority. If that ever changed, the refusal record would just be skipped; it would not error.
- **Migration:** idempotent `createIndex`. `down` tolerates missing namespace or index (codes 26/27). The runbook now includes
  `migrate --force`.
- **Owner WIP:** untouched, as in round 1.

## BLOCKER

None.

## MAJOR

None.

## MINOR

### MINOR-1: Any Cassandra outage longer than about 5 minutes dead-letters every pending audit event, and recovery is one event at a time
`app/Authorization/AuthorizationAudit.php:124-153`, `routes/console.php` (every minute).

How it happens:
- `retry()` increments `attempts` on **every** failure, including plain connection failures, and sets `dead_at` at 5.
- `stageEmbedded` first moves all embedded user and guard events into the outbox.
- So with Cassandra down for 5 scheduler runs (as it is right now with Docker down), every pending event becomes dead.
- After recovery nothing re-delivers them. `rbac:audit dead` lists at most 50 and `replay --event=` requeues one ID at a time.
- Separately, while Cassandra is down each run tries to connect once per event (up to about 100+ per run), with the client's default
  timeout. A run can then outlive the 5-minute overlap lock, so runs overlap. They stay idempotent but waste effort.

This matches the existing sources-history DLQ pattern, so it is not a regression of principle. But the DLQ's job (isolating poison
events) is being done by counting infrastructure outages.

Fix, any of:
- (a) Stop the batch without incrementing when the first projection fails with a connection error (`RuntimeException` from
  `CassandraClient`) and nothing in the batch was delivered.
- (b) Count attempts only for validation or encoding failures.
- (c) Add `rbac:audit replay --all`.

Option (a) also fixes the overlap.

## NIT

- **NIT-1** `AuthorizationRepair.php:101`: the guard-repair audit event is partitioned under `$eligible[0]`, an arbitrary Super Admin.
  It therefore appears in that person's history as if it were about them. `event()` now accepts a string target, so use
  `SuperAdminGuard::ID` (`super-admins`) as the target.
- **NIT-2** `SuperAdminGuard::beginBootstrap` / `RbacBootstrap`:
  - A failed first bootstrap leaves `bootstrap_candidate_id` set forever. Choosing a different initial email afterwards then needs a
    manual Mongo edit, and `rbac:repair` does not handle it.
  - The runbook sentence "The guard atomically reserves the initial account ID before writes" is inaccurate. It reserves a
    *candidate marker*, not membership.

  Document both, or let `rbac:repair --guard --force` clear a stale candidate.
- **NIT-3** This issue predates the gate and is not fully closed. Clearing verification on an admin email change does not stop the
  takeover chain "Admin with `users.manage` changes a reader's email → password reset to the new address", because password reset does
  not require a verified email. Also, `GoogleAuthController` (existing user matched by `google_id`) sets `email_verified_at` for the
  account's *current* email even after it was changed to an unproven address. Consider notifying the old address, or requiring
  re-verification before reset, in 9.7b.
- **NIT-4** A crashed `rbac:repair --guard --force` leaves `repair_token` set. That blocks every promotion and Super Admin removal (409)
  until someone runs a new repair under maintenance mode with the token older than 60 minutes. The runbook should state this.
- **NIT-5** Refusal auditing in `AdminMiddleware` and `authorizeManagement` writes a Mongo and a Cassandra row on every refused GET.
  An insider Admin can inflate the audit by reloading. This is acceptable at this scale; consider rate-limiting identical refusals
  later.

## Verdict

**APPROVE WITH FIXES.** Every REVIEW-1 finding is addressed, each with a meaningful test, and I found no new blocker or major issue
from reading the code.

Merge is gated on control's pending runs once Docker is up: full suite, `tests/Integration`, `AuthorizationTest` x10, and pint. Fixing
MINOR-1 (approach (a)) before merge is recommended. The NITs can go to follow-ups.
