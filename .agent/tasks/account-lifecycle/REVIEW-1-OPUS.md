# REVIEW-1 — Gate 9.7b Slice A (Claude Opus 5.5, read-only)

## Verdict: APPROVE WITH FIXES (1 MAJOR, 4 MINOR, 3 NIT)

Main paths hold: revocation (web next request, remember cookie, API login/refresh/bearer, reset, Google), recover vs purge
linearized on the user document (`purge_after > $$NOW` vs `<= $$NOW`), Cassandra anonymize-before-delete, comments anonymized
in place, evidence kept, Stripe cancel idempotent, webhooks reconciled under a pseudonym, dry-run/logs without PII.
All 14 decision-log deviations accepted (12 = owner policy item: permanently banned users cannot self-delete).
Tests: 10 tests map one-to-one to TASK risks, none redundant.

## Findings

- **MAJOR-1** `app/Accounts/AccountPurge.php:104-110` `maintain()` treats a user in `purging` as an orphan; if `claim()` moved the
  user `pending → purging` and crashed before the workflow update, the next run deletes the workflow and the account stays
  `purging` forever (locked, unrecoverable, PII kept). Fix: orphan only when user is null or has no `deletion`. Test: extend the
  crash test.
- **MINOR-1** `AccountLifecycle.php:83` (`$setOnInsert`) / `:116`: stale workflow reused on re-request after a crashed recover
  (old dates/steps/`stripe_canceled` → Stripe cancel never retried for a new subscription); recover racing a re-request deletes
  the new workflow. Fix: refresh fields + `$unset steps/stripe_canceled` on request; recover deletes only the workflow whose
  `requested_at` matches.
- **MINOR-2** `app/Authorization/UserAuthorization.php:151`, `snapshot()` `:199-206`: promotion can race a deletion request
  (write filter lacks a `deletion` guard) → pending admin never purged. Fix: add `'deletion' => ['$exists' => false]`.
- **MINOR-3** `Admin/UserController.php:270-273`, `admin/users/edit.blade.php`: admins can undo a user's own (self-origin)
  deletion; TASK decision 4 grants admins recovery of admin-origin only. Fix: restrict admin recover + button to `origin=admin`.
- **MINOR-4** `AccountPurge.php:35,216-224,279-294`: lease renewed only at checkpoints; a long `cassandra_anonymize` (>10 min)
  fails forever. Fix: renew the lease per month bucket.
- **NIT-1** `AccountPurge.php:337-341`: `user_document` checkpointed even if the delete matched nothing; throw if the user still
  exists.
- **NIT-2** `AccountPurge.php:176`: staff-refusal log lacks the workflow id.
- **NIT-3** `InteractionStream.php`, `ReadingPassHistory.php`: one `countDocuments` per stream event; acceptable.
