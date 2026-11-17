# Gate 9a — Review round 1 (independent, read-only, Claude Opus 5.5 subagent, 2026-11-17)

Verdict: **PASS_WITH_NOTES**. Manifest 41/41 OK. No BLOCKER/MAJOR. D1–D8 RESOLVED; owner decisions 1–3 honoured; rules 1–10, 12, 13 PASS; 11 and 14 PARTIAL.

MINOR
1. Article page N+1: `canReport()` per comment in comment-kebab.blade.php:4 (+ `canComment()` per comment) — compute once in ArticleController::show.
2. Muted/banned users can still edit their comments, incl. a hidden one (CommentController::update) — rule 14 gap.
3. Concurrent double appeal submit overwrites appeal_message (ModerationService::submitAppeal) — conditional update.
4. hideComment overwrites hidden_by_sanction_id unconditionally (race) — add where is_hidden=false.
5. resolve vs sanction through different report ids of one comment can deadlock; resolveCase deadlock → 500 — lock all pending reports of the comment.
6. down() drops snapshot/revoke/hide-link columns (evidence loss on rollback) — refuse when evidence data exists.
7. Rule 11: appeal outcome not shown on the case page.
8. Case page decision form keyed on the URL report, not the case's pending reports.
9. 9b seam leaks: SQL route-model binding, Eloquent relations in views, User::isBanned/isMuted/activeSanction query SQL directly, API-token hooks bound to Sanction Eloquent events — route via ModerationQueries for 9b.
NIT
10. Token hooks run before commit (fails safe). 11. Replies to hidden comments accepted. 12. Hidden notice text shown to admins too. 13. Orphan legacy rows skip author check. 14. ModerationDemoSeeder resets mod-* passwords; keep it unwired.
