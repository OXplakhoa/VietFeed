# REVIEW-2 — Gate 9.7b Slice B, fix round (Claude Opus 5.5, read-only)

## Verdict: APPROVE

Google email-match takeover closed for both cases (admin-changed address awaiting re-verification; victim with a different
`google_id`), the link is a conditional write (email, `google_id`, `deletion`, `api_token_epoch` unchanged), duplicate key 11000
→ login error not 500, legitimate Google sign-in (new account, `google_id` match, verified-email match of an ordinary account)
works, Slice A recovery unchanged, email change clears `remember_token`, notice to the old address on admin and self-service
changes after commit, admin email `lowercase` rule.

Control final gate (2026-11-24 01:33): `composer run test` exit 0 (591/591), `tests/Integration` exit 0 (36/36),
`AccountLifecycleTest` x3 exit 0, pint on changed files exit 0, `git diff --check` exit 0.

## New findings (follow-ups)

- **NIT-1** `GoogleAuthController.php:51-62`: a pending-deletion account that also has a different `google_id` or awaits
  re-verification gets the login error instead of the recovery page (safe; password sign-in still reaches recovery).
- **NIT-2 (pre-existing)** `GoogleAuthController.php:84-99`: an ordinary never-verified account without `google_id` is linked and
  verified on a verified-email Google match ("pre-registered address" case: the pre-registrant keeps password access). Refuse or
  reset the password when `! hasVerifiedEmail()` on an email match. Out of 9.7b scope.
