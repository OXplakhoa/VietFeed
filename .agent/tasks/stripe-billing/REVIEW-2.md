# Gate 7 — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-17)

Verdict: **PASS_WITH_NOTES**. No BLOCKER/MAJOR. All 28 manifest files matched; extra untracked app/Billing/SubscriptionMissing.php (one-line exception class) noted.

REVIEW-1 findings: F1 RESOLVED, F2 RESOLVED, F3 RESOLVED, F4 RESOLVED (residual orphan customer after 24 h key window documented), F5 RESOLVED in code but NOT PROVED by tests, F6 RESOLVED (interleaving not simulated).
TASK items 1-12 PASS, 13 (docs) PARTIAL.
C1 confirmed: laravel-mongodb Builder.php:434 array_fill_keys($columns) cannot take withCount Expression columns; manual grouped counts justified.

Findings:
1. MINOR — docs/demo/billing-demo.md (~97,106-116) and docs/database/nosql-local.md (~751-779) still describe the round-0 design (ordering_marker, status rank, sparse index, tombstones, "Stripe retry reclaims it"); no `stripe events resend` recovery for `stripe listen`.
2. MINOR — stale-writer fencing correct in code (lock_until = receipt expiry, $$NOW server clock, fenced write and ack) but no test proves a stale worker's write/ack is rejected; FakeStripeClient::$onRetrieve unused.
3. MINOR — sequence tests use one static snapshot; never exercise Stripe state changing between serialized retrievals.
4. MINOR — entitlement does not check price_id against STRIPE_PRO_PRICE_ID; `type` defaults to pro.
5. MINOR — under `stripe listen`, a 503 (lock wait exceeded after a crashed holder, or Stripe retrieve error) loses the event; covered by doc recovery step.
6. NIT — placeholder {_id, stripe_subscription_id} docs left on unknown_user / retrieve failure (ignored by entitlement).
7. NIT — concurrent customer creation: same key + params, 409 in-progress -> retry flash; acceptable.
8. NIT — migration down() not idempotent; duplicate legacy stripe_id across users would block up() (documented).
9. NIT — comment Pro badge does one Mongo distinct per comment (not a regression).
