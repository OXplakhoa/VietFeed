# Gate 9 recon — Report / moderation baseline (control, 2026-11-17)

Base: `main` 8cda49c. Method: throwaway probe `ReportProbeTest.php.txt` (this folder; not part of the suite) run with
`vendor/bin/phpunit` on isolated stores (Mongo `vietfeed_test`, SQLite `:memory:`), driving the real routes end to end.

## Storage today

- `Comment`, `Report`, `Sanction`: SQL (default connection). `User`, `Article`: MongoDB. Cross-store references are strings
  (`reports.reporter_id`, `sanctions.user_id/admin_id`, `comments.user_id`, `comments.article_id` = Article UUID).
- `reports.comment_id` FK `cascadeOnDelete`; `sanctions.report_id` FK `nullOnDelete`.
- No feature test covers report/sanction/appeal flows (only ApiTokensTest touches Sanction for token revocation).

## Reproduced defects (probe output)

| # | Step | Result |
|---|---|---|
| D1 | Article page that has any comment | **500** `Attempt to read property "name" on null` in `components/comment.blade.php` — no user can see comments or the report form |
| D2 | Admin reports index / filter / show; admin sanction show | **500** `Missing required parameter for [Route: articles.show] [slug]` (comment→article relation across SQL→Mongo) |
| D3 | Admin reports search `q`; admin sanctions search `q` | **500** `BadMethodCallException: This method is not supported by MongoDB` (`whereHas` on Mongo User) |
| D4 | Sanction issued with `comment_id` | 302 success but comment **not hidden** (`is_hidden` not fillable → silently dropped) |
| D5 | Delete a comment | all its reports **deleted** by FK cascade — moderation evidence lost |
| D6 | Admin report/sanction `destroy` | hard delete, no audit |
| D7 | Report status update | any status incl. back to `pending`, no reason, no audit; `reviewed` has no defined meaning |
| D8 | Appeal approved | sanction inactive, but hidden comment / report case not revisited (no defined behaviour) |

Working today: report submit (302 + flash), duplicate report blocked, self-report 403, admin cannot report, temp-ban blocks
commenting with expiry flash, permanent ban redirects to `/banned`, appeal submit, admin pending-appeal list, appeal approve,
sanction → report `actioned`, admin dashboard and user edit pages.

## Target (spec)

Spec §2.3, §7 stories 14 and 21, §11 Moderation Module, §12.5, §16 item 5; technical-gates Gate 9; project-scope-notes
"Report and moderation"; database-feature-map: Mongo current case state (`reports`, `sanctions`), Redis review queue/counters,
Cassandra audit `partition: case_id, clustering: occurred_at ASC, event_id`. Neo4j optional, not MVP. Acceptance tests must
come from expected behaviour, not the current implementation.
