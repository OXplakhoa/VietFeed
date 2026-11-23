# REVIEW-2 — Gate 9.7b Slice A, fix round 1 (Claude Opus 5.5, read-only)

## Verdict: APPROVE

All 13 findings from REVIEW-1-OPUS and REVIEW-1-HYDROGEN are fixed or accepted as documented retention (Stripe subscription
`metadata.user_id` and stream DLQ entries hold internal ids only). No regression. Verified in detail: `request()` refresh upsert
(filter `state $nin [purging, purged]`, duplicate key 11000 swallowed safely because the conditional user write still refuses);
`recover()` deletes only the workflow with the matching `requested_at`; `BillingGateway::apply()` pseudonym guard (`account_purged
$ne true` on live writes; lost race fails the receipt and Stripe retries into the gone-account path).

Control re-ran: `composer run test` exit 0 (591/591), `php artisan test tests/Integration` exit 0 (36/36).

## New finding

- **NIT-1** `app/Accounts/AccountLifecycle.php:89-93`: two simultaneous deletion requests (self + admin) let the loser's workflow
  `$set` overwrite the winner's dates/origin/steps before its user write is refused. Benign (Stripe cancel retried idempotently;
  claim uses the user's `purge_after`). Accepted as a follow-up.
