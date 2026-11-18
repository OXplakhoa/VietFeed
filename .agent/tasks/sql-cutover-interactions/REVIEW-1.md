# Gate 9.5 slice 3a — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **PASS_WITH_NOTES**. Manifest 34/34 OK. Outcomes 1–7 PASS (3 and 5 with notes). Toggle atomicity (unique index + 11000
retry, delete by exact _id), one event per successful change after the write, Redis-down safety, bounded Cassandra partitions
((user_id, year_month), (article_id, activity_day)), idempotent rows, versioned trending projection with Mongo rebuild, temp-ban 403s
on web and /api/v1, batched counts, storage-only test edits: OK.

- MINOR-1: rerunning `interactions:import-sql` after cutover recreates memberships users removed in Mongo (firstOrCreate by
  user+article; SQL tables kept) and with --emit republishes BOOSTED.
- MINOR-2: removed-boost version markers stay in `interactions:trending:v1` (unbounded; toggles unthrottled); if LRU drops the key,
  new events recreate it with few scores and home ranks by the partial Redis score instead of the count fallback until a manual rebuild.
- MINOR-3: synthetic demo rebuild writes the same key as real trending.
- MINOR-4: a lost UNBOOSTED publish leaves trending +5 until `interactions rebuild` and Cassandra lacks the removal (documented).
- NIT-1: DemoEngagementSeeder writes memberships without events (trending/Cassandra empty until rebuild/reemit).
- NIT-2: admin sort-by-count and dashboard aggregate the whole collection (one aggregation, O(collection)).
