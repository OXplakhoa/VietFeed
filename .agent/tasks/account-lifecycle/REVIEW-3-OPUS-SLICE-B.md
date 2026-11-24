# REVIEW-1 — Gate 9.7b Slice B (Claude Opus 5.5, read-only)

## Verdict: APPROVE WITH FIXES (1 MAJOR, 2 NIT)

Gemini 4 Hydrogen (REVIEW-3-HYDROGEN-SLICE-B.md) independently found the same issue and rated it BLOCKER.

- **MAJOR-1** `app/Http/Controllers/Auth/GoogleAuthController.php:50-52, 87-108`: admin with `users.manage` sets a reader's email
  to an attacker-controlled Google address; Google callback falls back to the email match, logs the attacker in (remember), links
  the attacker's `google_id`, marks the email verified, which also re-opens password reset. Also when the victim already has a
  different `google_id` linked, the email match still logs the attacker in (pre-existing). Fix: skip the email fallback when the
  account awaits re-verification (`password_reset_requires_verified_email && email_verified_at === null`) or has a different
  `google_id`; show a login error; turn the `User::create` duplicate-key path into that error instead of a 500; one assertion in the
  existing email-change test.
- **NIT-1** `app/Authorization/UserAuthorization.php:140`: self-service email change does not notify the old address (hijacked
  session → change → verify → reset goes unnoticed). Fix: drop the `admin_update` condition.
- **NIT-2** `app/Models/User.php:89`: `favorite_category_ids => 'array'` cast still triggers the laravel-mongodb deprecation
  (stores a JSON string). Out of contract; follow-up.

Verified OK: reset request/redemption eligibility (generic responses, conditional write, token kept on refusal), Google by
`google_id` verifies only a matching address, API login/remember cookie, old-address notice after commit with generic text,
Slice A interaction, audit page (one `whereIn`, Blade escaping, Cassandra-down state), permissions cast removal keeps native arrays,
REVIEW-3 NIT-1/NIT-2 fixes, last-Super-Admin copy, Story labels, test budget (4 existing tests extended, no new methods).
