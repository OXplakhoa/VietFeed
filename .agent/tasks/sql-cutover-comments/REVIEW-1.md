# Gate 9.5 slice 2 — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-18)

Verdict: **FAIL** (1 MAJOR). Manifest 32/32 OK. Runtime paths, hide/unhide (owner decision 1, R4, F1), cascade, counts, visibility,
Cassandra comment_ref, canonical-match on reemit, test edits: OK.

- MAJOR M1: 9b `SqlImport` now writes/looks up comment ids as strings; a live 9b case keyed by int (e.g. 5) is not found for legacy
  SQL reports of comment 5 → second case `"5"` (unique index accepts different BSON types) → `comments:import-sql` remap
  `updateMany({comment_id: {$in:[5,"5"]}})` hits E11000 and fails on every rerun; same for reports {reporter_id, comment_id}.
  The 9b live-merge test lost its meaning (now uses a Mongo UUID in SQL reports.comment_id).
- MINOR m1: Cassandra `ALTER TABLE … ADD comment_ref` swallows every RuntimeException (unreachable Cassandra reported as success).
- MINOR m2: comments hidden by a legacy sanction with no imported sanction keep a numeric hidden_by_sanction_id; count not printed.
- NIT n1: sparse index on hidden_by_sanction_id ineffective (explicit nulls). NIT n2: parent_id/article check unchanged (as before).
