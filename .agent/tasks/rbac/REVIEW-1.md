# Gate 9.7a RBAC — Independent review 1

Reviewer: Opus 5.5 subagent, 2026-11-23. Scope: `git diff main` (bcdcbba) on `rbac/permissions`, excluding the listed owner WIP, plus the
untracked RBAC files (`app/Authorization/*`, `app/Console/Commands/Rbac*.php`, `AuthorizationAuditController`,
`resources/views/admin/authorization/audit.blade.php`, `tests/Feature/AuthorizationTest.php`), plus control's edits to the two opt-in
integration tests. Review only. No files edited except this report.

## Verification I ran (isolated stores only)

| Command | Result |
|---|---|
| `php artisan route:list --path=admin --json` | 48 routes. Every route is named `admin.*` and has `admin` + `can:<ability>`. The mapping matches the GATE-REPORT table. No unmapped route. |
| `php artisan test tests/Feature/AuthorizationTest.php` (4 full runs + 10 single-test runs) | 3 of 4 full runs passed. **1 failure** in `test_remaining_super_admin_cannot_be_demoted_or_deleted_by_either_path` (line 146: expected `account_deleted`, got `super_admin_removal_refused`). See MINOR-4. |
| `composer run test` | 569 passed, 11,452 assertions, exit 0 |
| `php artisan test tests/Integration/StoryPageProjectionsTest.php tests/Integration/StoryProjectionsTest.php` | 26 passed, 688 assertions |
| Owner WIP mtimes (`CLAUDE.md`, `SourceSeeder.php`, `.agent/tasks/*/STATUS.md`, `pi-warden.md`) | All still dated 2026-11-16 or earlier, so the gate did not touch them. No changes to `composer.*`, `package*.json` or migrations. |

## Final Super Admin invariant: step-by-step analysis

Design: the guard is `authorization_control/super-admins.user_ids`. The rules are: promote = user CAS write, then `$addToSet`. Remove =
conditional `$pull` (`user_ids` contains X AND `user_ids.1` exists), then user CAS write (filter `_id + role + authorization_version`).

Invariant I1: guard ⊆ committed Super Admins. Invariant I2: |guard| ≥ 1 after bootstrap. Together they imply at least one Super Admin.

- **Two concurrent demotions (A, B; guard {A,B})**: the first pull leaves {B}. The second pull needs `user_ids.1`, so it fails and returns
  409 with an audit row. Correct. The test interleaves this for real against Mongo.
- **Demote A + delete A**: only one request can pull A, and the other returns 409. Correct.
- **Demote A + delete B**: the same reasoning as two demotions applies. Correct.
- **Stale model reads**: a stale "admin" view of a now-Super-Admin skips the reservation, but the CAS on `role`/`authorization_version`
  fails with 409. A stale "super" view of a demoted user fails the reservation. Both fail closed.
- **Failure between the two writes**: on removal, the guard has already lost the ID and the user stays a Super Admin. The account becomes
  "unremovable until repair", which is conservative. On promotion, the user is committed and not in the guard, which is also
  conservative. Neither case can produce zero Super Admins. The repair runbook in `nosql-local.md` is adequate.
- **Profile self-delete** (`ProfileController::destroy`) uses the same `delete()` and guard. A raw `deleteOne` replaces
  `$user->delete()`. The only `deleted` hook was token cleanup, and that is replicated. Correct.
- **Ban paths**: a permanent ban of a Super Admin is refused and audited. A temporary ban or mute does not block `/admin`
  (`CheckUserSanction` only acts on permanent bans). A ban racing a promotion is fenced by `authorization_ban_pending` + version CAS.
  Correct.
- **Other deletion/role paths**: none. Grep found no other `User` delete or `role` writer. `role`/`permissions` are not fillable.
  Google OAuth `'role' => 'user'` is now ignored, and the default is `user`.
- **Bootstrap**: idempotent after the marker. The only hole is the first-run rebuild race (MINOR-2).

Conclusion: under normal operation the system cannot reach zero Super Admins. A guard/user mismatch can only fail closed (block). The one
exception that could grant wrongly is the documented "writers paused" precondition of the first bootstrap.

## BLOCKER

None.

## MAJOR

### MAJOR-1: A `moderation.manage` Admin can permanently ban (lock out) other Admins
`app/Moderation/ModerationService.php:112-126, 397-410`; `app/Authorization/UserAuthorization.php:97-110`.
`beginPermanentBan` only refuses Super Admin targets. `issueSanction` has no staff check. Any Admin holding only `moderation.manage`
can `POST /admin/sanctions {user_id: <other admin>, type: permanent_ban}`, or action a report against a staff author. The ban has three
effects:
- `CheckUserSanction` redirects every route of the victim to `sanctions.banned`.
- The victim's API token families are revoked.
- The victim's grants stay unchanged, but they are unusable.

This disables a staff account. The contract reserves disabling, editing and deleting staff accounts to Super Admins: "Super Admin ...
alone can ... edit/delete admins". It is a lateral privilege bypass that RBAC newly makes meaningful. Before this gate every admin was
all-powerful, so it did not matter. The same applies to temporary bans against staff API access (smaller impact).

Fix: in `beginPermanentBan`, or better at the top of `issueSanction` for `temporary_ban`/`permanent_ban`, refuse when
`$target->isAdmin()` and the actor is not a Super Admin. Audit the refusal (`staff_ban_refused`). Add one escalation test. Alternatively,
the owner can explicitly accept this as policy in the GATE-REPORT decision log.

## MINOR

### MINOR-1: Refused privilege-escalation attempts (403) are not audited
`app/Http/Controllers/Admin/UserController.php:71-75`; `UserAuthorization::edit:27-30`.
An Admin posting `role`/`permissions`, or trying to edit or delete a Super Admin/Admin, gets 403 and leaves no Cassandra row. The
contract asks for "every refused attempt to break an invariant". "Only Super Admins change roles or edit staff" is one of those
invariants. Currently only the final-Super-Admin, ban and promotion-while-banned refusals are recorded.

Fix: in `edit()`/`delete()`, wrap the two `authorize` calls. On denial, call `audit->record(event(..., 'privilege_change_refused'))`,
then rethrow.

### MINOR-2: First-run bootstrap rebuild can put a non-Super-Admin into the guard
`app/Console/Commands/RbacBootstrap.php:44-52`; `SuperAdminGuard::finishBootstrap` (`$set user_ids`).
Scenario: `write()` registers the initial user I in the guard. I promotes X, so the guard holds {I,X}. Bootstrap then reads Super Admins
{I,X}. Meanwhile, I demotes X: the pull leaves {I} and X becomes an admin. Bootstrap then `$set`s {I,X}. The guard now contains X, who
is not a Super Admin. Later, I's self-demotion succeeds because the guard holds 2 IDs. Result: zero Super Admins.

This needs Super Admin activity during the bootstrap window. The runbook says to pause writers, so impact is low.

Fix: skip the rebuild when the guard document already has `user_ids`, and only `$setOnInsert`/`$addToSet` IDs whose user
document is re-verified as `super_admin` right before the add. Alternatively, keep `$set` but refuse to run while any user has a
non-null `authorization_version` newer than the command start.

### MINOR-3: The `rbac:audit retry` schedule uses the default 24 h overlap mutex
`routes/console.php:18`. The neighbouring entries use `withoutOverlapping(1)`. This one uses `withoutOverlapping()`, which defaults to
1440 minutes. If a retry process is killed (deploy, OOM, Cassandra hang), the cache mutex persists. Audit replay then silently stops
for up to 24 h. Fix: use `withoutOverlapping(5)`.

### MINOR-4: The audit-order assertion is flaky, and audit ordering depends on the app server clock
`tests/Feature/AuthorizationTest.php:146`. The test failed 1 time in about 14 runs, with no concurrent suite running (checked in
`storage/logs` for that window). `forUser()[0]` returned an earlier `super_admin_removal_refused` row instead of `account_deleted`.
`occurred_at` comes from PHP `now()` at event creation, and the clustering is `occurred_at DESC`, so a backward step of the WSL2 clock
reorders rows.

Fix: in the test, assert that `account_deleted` is present in `array_column($events, 'reason')`, and do not rely on position. In docs,
note that the order follows the app clock. Optionally, derive `at` from the UUIDv7 timestamp so the two never disagree.

### MINOR-5: Interrupted-write markers have no operator tooling
`UserAuthorization.php:74-84, 105-115`. A stuck `authorization_deleting` blocks every future role change for that account (`abort_if`
returns 409 forever). A stuck `authorization_ban_pending` blocks future permanent bans of that user ("Quyền của tài khoản vừa thay
đổi") and blocks promotion. Repair is a manual Mongo edit, which is documented. Consider a small `rbac:repair --user=` that verifies and
clears the marker, or at least surface the marker on the user edit page for Super Admins.

## NIT

- **NIT-1** `UserAuthorization.php:154`: demoting `super_admin` → `admin` with the full catalog does not bump `api_token_epoch` because
  the effective sets are equal. This is harmless today because the API has no admin scopes. Revisit when API scopes land.
- **NIT-2** `UserController::update`/`destroy`, `ProfileController::destroy`: invariant refusals surface as a bare 409 error page, not a
  flash message on the form. Consider catching `HttpException(409)` and redirecting back with `error`.
- **NIT-3** `UserController::update:96`: after a successful self-demotion the redirect goes to `admin.users.index`. That now returns 403 for
  the demoted actor. Redirect to `dashboard` or `home` when `$user->is($request->user())`.
- **NIT-4** `database/seeders/UserSeeder.php:23`: `$this->command->call(...)` fatals if the seeder runs without a command, for example
  `app(UserSeeder::class)->run()`. Use `Artisan::call` or the null-safe operator with an explicit error.
- **NIT-5** `AuthorizationAudit::retry`: a single permanently failing outbox event blocks all later events (head-of-line). The
  per-minute `users` scan on `authorization_audit_pending.0` has no index. Both are fine at current volume.
- **NIT-6** This issue predates the gate and is out of scope: `users.manage` lets an Admin change a plain user's email without resetting
  `email_verified_at`. Combined with a password reset, that allows takeover of a reader account. The gate limits this correctly to plain
  users, but consider resetting verification on an admin email change.
- **NIT-7** `users/edit.blade.php`: after a validation error with no boxes ticked, `old('permissions', $user->permissions)` falls back to the
  stored grants. The form then re-displays permissions the Super Admin had just cleared.

## Other checks (no finding)

- **Route coverage**: every `/admin` route goes through `AdminRoutes::middleware` (`LogicException` at boot for unmapped names). The
  matrix test enumerates `Route::getRoutes()` by URI, fails on an unnamed or unmapped route, and asserts both `can:<ability>` in
  `gatherMiddleware()` and real allow/deny behaviour for 10 identities × every method. The probe middleware stops before controllers,
  and the denials prove `can:` runs first. Staff-target policy and escalation are covered separately. The tests are meaningful.
- **Mass assignment**: `role` and `permissions` are removed from `$fillable`, and there is a test showing `fill()` and profile-update
  attempts are ignored. Registration, Google OAuth and API v1 do not write the role. The `users.update` validation rejects unknown
  permissions, permissions on non-admins, and duplicates.
- **Fresh reads**: permissions are read from the Mongo user loaded by the Eloquent session provider on each request. No session or
  cache copy exists. Revocation, and demotion to `user`, bump the epoch in the same write as the role change, so stale access and
  refresh tokens fail even if Redis cleanup fails. Tests cover both web and API.
- **Audit**: partitioning matches the contract (by target user, plus a TTL-bounded daily bucket). Events use UUIDv7 IDs. Replays are
  idempotent through the same primary key. The outbox is embedded in the same user write (durable, with no Redis dependency). Events
  move to a standalone collection before a physical delete. Cassandra down → change commits, page and CLI show "unavailable", no 500.
  The payload has no PII beyond IDs and role/permission state.
- **Bootstrap on dev** (what it will change): `admin@vietfeed.com` (or the chosen email) → `role: super_admin`. That account and every
  `role: admin` user get `authorization_version` and an embedded outbox event, and every Admin gets `permissions = ALL`. The command
  then creates `authorization_control/super-admins`. A rerun with the same email is a no-op and prints 0/0. Another email is refused.
  An unknown email is refused. Plain users are untouched.
- **Integration test edits by control**: the anchor-scoped UUID allowance in `StoryPageProjectionsTest`, and the JSON round-trip
  comparison in `StoryProjectionsTest`, are narrow and correct. Both pass.
- **Docs**: the `nosql-local.md` section and the GATE-REPORT route table match the code.

## Verdict

**APPROVE WITH FIXES.** The core RBAC enforcement and the Super Admin guard hold up well. Fix MAJOR-1, or have the owner explicitly
accept it, and preferably MINOR-1..4 before merge. The other findings can go to follow-ups.
