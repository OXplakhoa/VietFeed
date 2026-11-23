<!-- Read-only security review by Gemini 4 Hydrogen (agy, plan mode), 2026-11-24, Slice B. -->
# Gate 9.7b Slice B — Independent Security Review (NIT-3 Email-Change Takeover)

## Verdict: **BLOCK (NOT CLOSED)**

The takeover chain is **not closed**. An admin with `users.manage` can still take over any plain user (`role = user`) immediately and with **zero victim interaction** via Google OAuth verified-email matching, which also sets `email_verified_at` and **re-opens password reset**.

---

## Findings

### [BLOCKER-1] Google OAuth verified-email match grants immediate takeover and re-opens password reset
- **Locations:** [GoogleAuthController.php:51-55](app/Http/Controllers/Auth/GoogleAuthController.php#L51-L55), [L91-L93](app/Http/Controllers/Auth/GoogleAuthController.php#L91-L93), [L107-L115](app/Http/Controllers/Auth/GoogleAuthController.php#L107-L115); [User.php:95-100](app/Models/User.php#L95-L100); [UserAuthorization.php:132-139](app/Authorization/UserAuthorization.php#L132-L139)
- **Why:** After an admin changes a user's email, `UserAuthorization::write` sets `email_verified_at = null` and `password_reset_requires_verified_email = true`. However, `GoogleAuthController::callback` falls back to `User::where('email', $email)->first()` whenever Google reports `email_verified === true`, ignoring both `email_verified_at === null` and `password_reset_requires_verified_email === true` (and even matching accounts that already have a different `google_id`).
- **Concrete attack steps:**
  1. Malicious admin (`users.manage`) updates victim (`role = user`) via `PATCH /admin/users/{user}` ([Admin/UserController.php:88](app/Http/Controllers/Admin/UserController.php#L88)) to an attacker-controlled Google address `attacker@gmail.com`.
  2. Admin signs in with Google (`GET /auth/google/redirect` → callback) using `attacker@gmail.com`. `User::where('google_id', ...)` returns `null`, so `$findByIdentity` matches the victim by `email`.
  3. Callback runs `Auth::login($user, remember: true)`, links the attacker's `google_id` if unset (`L91-L93`), and calls `$user->markEmailAsVerified()` (`L107-L109`).
  4. Setting `email_verified_at` satisfies `orWhereNotNull('email_verified_at')` in `User::scopeEligibleForPasswordReset` ([User.php:97-99](app/Models/User.php#L97-L99)), re-opening password reset as well.
- **Fix:**
  - **Minimal drop-in fix:** In `GoogleAuthController::callback`, look up by `google_id` first; if falling back to `User::where('email', $email)->first()` and the user exists with `! $user->hasVerifiedEmail()` or `$user->getAttribute('password_reset_requires_verified_email') === true`, **refuse sign-in** (redirect to `login` with error — do not fall through to `User::create()` which would hit the `users.email` unique index `11000`).
  - **Stronger architectural fix (recommended if locked-out typo recovery is not needed):** Store admin email edits as `pending_email` + confirmation token and keep `users.email` unchanged until confirmed by the user.

### [MINOR-1] `remember_token` and active web sessions are not invalidated on admin email change
- **Locations:** [UserAuthorization.php:136-148](app/Authorization/UserAuthorization.php#L136-L148), [L181](app/Authorization/UserAuthorization.php#L181)
- **Details:** Email changes increment `api_token_epoch` (revoking API tokens via [ApiTokenService.php:82-84](app/ApiTokens/ApiTokenService.php#L82-L84)), but leave `remember_token` intact (unlike [AccountLifecycle.php:103](app/Accounts/AccountLifecycle.php#L103) and [User.php:107](app/Models/User.php#L107)). An admin cannot forge a remember cookie without `APP_KEY` or cookie theft, so this is not a standalone takeover path, but existing remember cookies survive an identity change.
- **Fix:** Add `'remember_token' => null` to `$set` in `UserAuthorization::write` when `$emailChanged` is true.

### [NIT-1] Best-effort passive notice & missing `lowercase` email validation on admin edit
- **Locations:** [UserAuthorization.php:206-213](app/Authorization/UserAuthorization.php#L206-L213); [AdminEmailChanged.php:15-21](app/Notifications/AdminEmailChanged.php#L15-L21); [Admin/UserController.php:76](app/Http/Controllers/Admin/UserController.php#L76)
- **Details:** `AdminEmailChanged` mail transport failures are swallowed (warning log only) and the mail contains no freeze/revert link. Also, `Admin\UserController::update` lacks the `'lowercase'` rule present in [ProfileUpdateRequest.php:23](app/Http/Requests/ProfileUpdateRequest.php#L23), risking case-mismatch misses in MongoDB `email` queries.
- **Fix:** Add `'lowercase'` to `Admin\UserController::update` email validation; queue `AdminEmailChanged` or include a support/freeze action.

---

## Path-by-Path Verification Matrix

| Attack Path | Outcome | Verified Evidence |
|---|---|---|
| Google OAuth (verified-email match / new `google_id` link) | **VULNERABLE (BLOCKER-1)** | [GoogleAuthController.php:51-55,91-93,107-114](app/Http/Controllers/Auth/GoogleAuthController.php#L51-L55) |
| Google OAuth (`google_id` match) | Mitigated | `L107` requires `$user->email === $email` before `markEmailAsVerified()`; does not verify attacker's changed email. |
| Password reset request & redemption | **Re-opens after BLOCKER-1** | [PasswordResetLinkController.php:37-39](app/Http/Controllers/Auth/PasswordResetLinkController.php#L37-L39), [NewPasswordController.php:42-52](app/Http/Controllers/Auth/NewPasswordController.php#L42-L52), [User.php:95-120](app/Models/User.php#L95-L120). Blocked while `email_verified_at` is null, unlocked once `email_verified_at` is set. |
| Email verification link (`verify-email/{id}/{hash}`, resend) | Blocked without victim session | [routes/auth.php:45-55](routes/auth.php#L45-L55), [VerifyEmailController.php:15-26](app/Http/Controllers/Auth/VerifyEmailController.php#L15-L26), [EmailVerificationNotificationController.php:14-23](app/Http/Controllers/Auth/EmailVerificationNotificationController.php#L14-L23) are behind `auth` middleware. |
| Password login & API token login | Blocked | [LoginRequest.php:45](app/Http/Requests/Auth/LoginRequest.php#L45) & [TokenController.php:29-37](app/Http/Controllers/Api/TokenController.php#L29-L37) require victim password; existing API tokens revoked via `api_token_epoch`. |
| Remember cookie & session fixation | Cookie unrotated (MINOR-1); fixation mitigated | `session()->regenerate()` called on login ([AuthenticatedSessionController.php:33](app/Http/Controllers/Auth/AuthenticatedSessionController.php#L33), [GoogleAuthController.php:84,115](app/Http/Controllers/Auth/GoogleAuthController.php#L115)); `remember_token` not cleared on email change. |
