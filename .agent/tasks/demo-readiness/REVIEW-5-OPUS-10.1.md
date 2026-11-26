# REVIEW-4 — Gate 10.1, fix round 3 (Claude Opus 5.5, read-only)

## Verdict: APPROVE

Both Gemini 4 Hydrogen findings resolved, nothing new. Real-user purge unchanged (`deleted`; only an extra `context.synthetic=false`
cleared at purge; nothing reads the literal back). Synthetic flag read only from the live user document as exact boolean `true`,
backfilled once on resumed claims (context merged, `first_month`/`stripe_customer_id` kept). Anonymize guard (`SELECT user_id` +
`=== $id`) intact; re-runs are no-ops. `/admin/users` default `synthetic != true` lists every real user (missing field matches);
`?synthetic=1` is admin-only and persists across search/Pro/paging. Edge: crash after `user_document` leaves no user for the
backfill, but `cassandra_anonymize` is checkpointed earlier, so it never re-runs without the flag.
