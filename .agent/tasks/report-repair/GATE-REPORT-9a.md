# Gate 9a — Report repair and moderation acceptance

## Implementation

### Plan

- **Module seam.** New `App\Moderation\{ModerationService, ModerationQueries, ModerationException}`. `ModerationService` holds every
  command (report, resolve case, issue sanction, appeal, decide appeal, revoke) and is the only code that sets or clears
  `comments.is_hidden`; `ModerationQueries` holds the read side (inbox, case page, sanction list/detail). Controllers are thin
  and call only these two classes, so Gate 9b can swap storage behind them. Cross-store reads follow Gate 7 C1: ids resolved in one
  query per store, joined in PHP, batched per page (no `whereHas`/`withCount` across SQL and Mongo).
- **Schema** (one new migration `2026_11_17_100001_moderation_report_repair.php`, no old migration edited): see "Schema changes".
- **Test-first.** Four suites (`ModerationUserReportTest`, `ModerationAdminCaseTest`, `ModerationSanctionTest`,
  `ModerationEnforcementTest`) plus `tests/Concerns/ModerationFixtures.php`, written from the TASK contract before touching app code and
  run against unmodified code (see "Baseline run").
- **Rule to test map**: see below.

### D1–D8 diagnosis, fix and test

| # | Root cause | Fix | Test |
|---|---|---|---|
| D1 | `Comment::user()` was `belongsTo(User::class)`. With no explicit key Eloquent guesses the foreign key as `user_` + related primary key = `user__id` (User/Article primary key is `_id`), a column that does not exist, so the relation was **always null for every comment, on every article, for every viewer** (not only deleted authors). Same defect in `Comment::article()` (`article__id`), and latent in `Boost::user/article`, `ArticleUnlock::user/article`, `Bookmark::user`. | Explicit keys `user_id` / `article_id` on all of them; the comment view is also null-safe and renders "Người dùng đã xóa" for a deleted author. | `ModerationUserReportTest::test_article_page_renders_*`, `test_report_control_is_not_offered_to_ineligible_users` |
| D2 | Same root cause: `comment.article` was null, so `route('articles.show', null)` threw. Views also assumed live comment/article. | Admin views read the report snapshot plus a live-state lookup (`visible`/`hidden`/`deleted`), article link only when `Article::find` succeeds ("Bài viết không còn tồn tại" otherwise). | `ModerationAdminCaseTest::test_case_page_*`, `ModerationSanctionTest::test_sanction_page_renders_when_comment_or_article_is_gone` |
| D3 | `whereHas('reporter'/'user')` on a Mongo model (unsupported by laravel-mongodb). | Reporter/author ids resolved with one Mongo regex query on `users.name` (escaped, case-insensitive, capped at 500), then `whereIn` on SQL; text search uses `LIKE ... ESCAPE '!'` on the report snapshot/description/reason. | `test_inbox_search_*`, `test_sanction_list_search_*` |
| D4 | `is_hidden` was not in `$fillable`, so `update(['is_hidden' => true])` was silently dropped. | Explicit path `ModerationService::hideComment()` (query-builder update that also records `hidden_by_sanction_id`), only when the admin ticks "Ẩn bình luận bị báo cáo". `is_hidden` stays non-fillable (tested). | `test_sanction_from_case_actions_all_pending_reports_*`, `test_comment_hiding_only_through_moderation_path` |
| D5 | `reports.comment_id` FK `cascadeOnDelete`. | FK dropped; snapshot columns on the report. | `test_deleting_a_comment_by_author_or_admin_keeps_reports_and_snapshots`, `..._parent_comment_keeps_reports_on_its_replies` |
| D6 | `destroy` actions hard-deleted reports/sanctions. | Routes `admin.reports.destroy` and `admin.sanctions.destroy` removed (DELETE returns 405). Reports are dismissed; sanctions are revoked via `POST admin/sanctions/{id}/revoke` (`is_active=false`, `revoked_at/by/note/via`). | `test_report_and_sanction_cannot_be_hard_deleted_from_admin`, `test_revoke_*` |
| D7 | `update` accepted any status including `pending`/`reviewed`, no audit. | Only `dismissed`/`false_report` accepted, only from `pending`, all pending reports on the comment resolved together with `admin_id`, `decided_at`, `decision_note`. `reviewed` removed (scope, label, filter, column data). | `test_dismiss_*`, `test_only_pending_cases_can_be_decided_*`, `test_reviewed_status_is_gone_*` |
| D8 | Approving an appeal only flipped `is_active`. | `decideAppeal`: only `pending` appeals; approve revokes (via=`appeal`), unhides comments whose `hidden_by_sanction_id` is that sanction, case stays `actioned`; deny changes nothing but the appeal outcome; outcome/who/when/note recorded. | `ModerationSanctionTest::test_approved_appeal_*`, `test_denied_appeal_*`, `test_only_pending_appeals_can_be_decided`, `test_approving_appeal_does_not_unhide_a_comment_hidden_by_another_sanction` |

### Rule to test map

| Rule | Tests |
|---|---|
| 1 | `ModerationUserReportTest::test_article_page_renders_comments_with_author_name_and_report_control`, `..._whose_author_was_deleted`, `..._shows_replies_with_author_names_to_guests`, `test_report_control_is_not_offered_to_ineligible_users` |
| 2 | `test_verified_user_reports_comment_and_snapshot_is_stored`, `test_muted_user_can_still_report`, `test_report_validates_reason_and_description_length` |
| 3 | `test_rejected_reports_leave_no_record_and_explain_why`, `test_hidden_and_deleted_comments_cannot_be_reported` |
| 4 | `test_sanctioned_user_sees_active_sanction_and_appeals_once`, `test_appeal_on_someone_elses_sanction_is_refused`, `test_appeal_on_inactive_or_expired_sanction_is_refused` |
| 5 | `ModerationAdminCaseTest::test_inbox_lists_cases_newest_first_*`, `test_inbox_filters_by_status_and_reason`, `test_inbox_search_by_reporter_name_snapshot_text_and_description`, `test_inbox_pagination_keeps_filters`, `test_inbox_does_not_run_per_row_queries` |
| 6 | `test_case_page_shows_snapshot_live_state_all_reports_history_and_article_link`, `test_case_page_survives_deleted_comment_and_deleted_article`, `test_case_page_survives_deleted_author` |
| 7 | `test_dismiss_and_false_report_*`, `test_dismiss_without_note_is_allowed`, `test_only_pending_cases_can_be_decided_*`, `test_reviewed_status_is_gone_*` |
| 8 | `test_sanction_from_case_actions_all_pending_reports_*`, `test_sanction_from_case_does_not_hide_unless_chosen`, `test_sanction_from_case_rejects_wrong_user_and_non_pending_case`, `test_sanction_without_case_from_user_page` |
| 9 | `test_dismiss_and_false_report_record_decision_and_resolve_all_pending_on_comment` |
| 10 | `ModerationSanctionTest::test_sanction_list_filters_by_type_and_status`, `test_sanction_list_search_*`, `test_sanction_page_renders_when_comment_or_article_is_gone` |
| 11 | `test_approved_appeal_*`, `test_approving_appeal_does_not_unhide_*`, `test_denied_appeal_*`, `test_only_pending_appeals_can_be_decided` |
| 12 | `test_revoke_keeps_record_with_admin_when_and_note`, `test_revoke_unhides_comment_hidden_by_that_sanction_and_report_survives`, `test_revoke_keeps_hidden_a_comment_hidden_by_a_different_active_sanction`, existing `ApiTokensTest` (token hooks) |
| 13 | `ModerationEnforcementTest::test_deleting_a_comment_by_author_or_admin_*`, `..._parent_comment_*` |
| 14 | `test_mute_blocks_commenting_only`, `test_temporary_ban_blocks_write_actions_*`, `test_permanent_ban_redirects_to_banned_page_except_logout_and_appeal`, `test_expired_sanctions_stop_applying_without_any_job`, `test_warning_does_not_restrict_the_user` |
| Owner decision 1 | `test_hidden_comment_is_invisible_except_to_author_and_admins` |
| Demo | `test_demo_seeder_builds_a_reportable_case_and_is_idempotent` |

### Baseline run (new suites vs unmodified code, `main` 8cda49c app code)

48 tests then: 11 passed (behaviour that already worked: report submit, duplicate/self/admin guards, temp-ban comment block, perm-ban redirect,
appeal once), **37 failed or errored**, including every D1–D8 test. Reasons by group: D1/D2 `500` (null `comment.user`/`article`, missing
`slug`); D3 `500 BadMethodCallException`; D4 comment not hidden / report not `actioned` for sibling reports; D5 reports `0` after
comment delete; D6 route and column not defined (`revoke`, `revoked_at`); D7 `reviewed`/`pending` accepted, no `decided_at`; D8
comment stayed hidden, non-pending appeal decidable; plus missing snapshot columns, bookmark/boost not blocked for temp ban (`200` instead of
`403`; `BookmarkController`/`BoostController` never checked `canBookmark`/`canBoost`), no appeal form on `/banned`, no `revoked`
filter. Raw baseline log: `/tmp/claude-1000/base-tests.keep`.

### Schema changes and data migration

`database/migrations/2026_11_17_100001_moderation_report_repair.php` (new; old migrations untouched):

- `reports`: drop FK `comment_id` (keeps column, so reports outlive comments); `status` enum -> `varchar(20)` after
  `UPDATE ... 'reviewed' -> 'pending'`; rename `reviewed_at` -> `decided_at`; add `comment_body`, `comment_user_id` (index),
  `comment_article_id`, `decision_note`, `sanction_id` (index). Existing rows are **backfilled** with the live comment snapshot via
  correlated `UPDATE`.
- `sanctions`: add `revoked_at`, `revoked_by`, `revoke_note`, `revoked_via` (`admin`|`appeal`), `appealed_at`, `appeal_decided_at`,
  `appeal_decided_by`, `appeal_decision_note`. Legacy rows with `appeal_status=approved` and `is_active=false` get `revoked_at=updated_at`,
  `revoked_via=appeal`.
- `comments`: add `hidden_by_sanction_id` (index).
- Verified on a throwaway SQLite file (never dev data): legacy `reviewed` -> `pending`, snapshots backfilled, legacy approved-appeal
  sanction marked revoked, reports survive comment delete; `down()` then `up()` again both succeed.
- `down()` drops the new columns but does **not** restore the cascading FK or the enum (restoring them would delete or reject evidence);
  it is documented as forward-only for those two constraints.

### Decisions and why

- **Case = comment.** Inbox groups reports by `comment_id` (`GROUP BY` on SQL, counts and latest report batched); `admin.reports.show`
  still takes a report id (the latest one in the group is linked from the inbox) and shows the whole case.
- **Sanction links.** `sanctions.report_id` = the report the admin acted from; every pending report of the comment gets
  `reports.sanction_id` = the sanction. Issuing from a non-pending case, or for a user other than the comment author, is refused with a message.
- **Hiding** only on explicit checkbox and only when the comment is still visible. Admin revoke and an approved appeal both unhide comments hidden by that sanction (control decision, round 1).
- **Visibility** of hidden comments is filtered in the query (`Comment::visibleTo`) so the body never reaches non-authorised pages.
- **Appeals** allowed for any currently active sanction type (warning, mute, temp ban, perm ban); previously the UI excluded warnings and
  permanent bans, which made appeal impossible for banned users. The permanent-ban appeal form lives on `/banned`.
  `sanctions.appeal` is exempt from the permanent-ban redirect and moved out of the `verified` group (a banned/unverified user must still reach it).
- **Report route** moved from `auth+verified` to `auth` so unverified users get an explanatory flash instead of a redirect to the verification page.
  A permanently banned user is redirected to `/banned` by the middleware before the controller (still no record).
- **Rejections are redirect-with-flash (302)**, not 403 aborts (previously self-report/admin returned 403); second appeal and appeal on someone else's sanction stay 403.
- **Existing `ApiTokensTest` edited minimally** (the tests assert token/epoch hooks, which are unchanged): `DELETE admin/sanctions/{id}` became
  `POST admin/sanctions/{id}/revoke` (still redirects to the index), and the three approve/deny calls first mark the fixture appeal `pending`
  via a query-builder update (new rule: only pending appeals can be decided). One test creates a report without snapshot columns; the service
  falls back to the live comment author for such rows so that test is untouched.
- Enforcement gaps closed: `BookmarkController::toggle`/`BoostController::toggle` now return 403 JSON for temp/perm banned users (the model
  methods existed but were never called).
- Out-of-scope but same root cause fixed: `Boost`, `ArticleUnlock`, `Bookmark::user` relation keys (listed in D1).

### Files changed

New: `app/Moderation/{ModerationService,ModerationQueries,ModerationException}.php`,
`database/migrations/2026_11_17_100001_moderation_report_repair.php`, `database/seeders/ModerationDemoSeeder.php`,
`tests/Concerns/ModerationFixtures.php`, `tests/Feature/Moderation{UserReport,AdminCase,Sanction,Enforcement}Test.php`,
`docs/demo/moderation-demo.md`, this report.

Modified: `app/Http/Controllers/{ReportController,SanctionAppealController,ArticleController,BookmarkController,BoostController}.php`,
`app/Http/Controllers/Admin/{ReportController,SanctionController}.php`, `app/Http/Middleware/CheckUserSanction.php`,
`app/Models/{Report,Sanction,Comment,User,Boost,Bookmark,ArticleUnlock}.php`, `routes/web.php`,
views `admin/reports/{index,show}`, `admin/sanctions/{index,show}`, `admin/users/edit`, `components/{comment,comment-kebab}`,
`profile/_sanctions`, `sanctions/banned`, and `tests/Feature/ApiTokensTest.php` (see decisions).

### Verification

All commands run in `/home/dolakhoa/code/VietFeed`; exit codes are those of the real command.

| # | Command | Exit | Result |
|---|---|---|---|
| 1 | `vendor/bin/phpunit --filter Moderation` x3 | 0, 0, 0 | 49 tests / 523 assertions each |
| 1 | `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` x3 | 0, 0, 0 | 70 tests / 1453 assertions each |
| 2 | `composer run test` | 0 | **290 tests, 4480 assertions** (baseline 241 / 3957); no notices/warnings reported |
| 3 | `composer check-platform-reqs` | 0 | ok |
| 3 | `vendor/bin/pint --test` on every touched/new PHP file | 0 | passed (Pint was applied first; four unrelated files it reformatted by accident were restored exactly) |
| 4 | probe copy in `/tmp/claude-1000/probe/` | 0 | every D1–D3 step now 200/302 with no exception (article page 200 with report form; admin index/filter/search/show 200; sanctions search/show 200). Expected remaining notes: `reviewed` update now rejected (validation), comment delete keeps `reports=1`, comment not hidden because the probe posts the old `comment_id` field instead of `hide_comment` |
| 5 | `php artisan route:list --path=admin/reports`, `--path=admin/sanctions`, `--name=reports` | 0, 0, 0 | reports: index/show/update (no destroy); sanctions: index/store/show/update + `POST {sanction}/revoke` (no destroy); `reports.store` present |
| 6 | `sha256sum -c /tmp/claude-1000/gate9a-wip.sha256` | 0 | all six owner WIP files unchanged |
| - | `bootstrap/cache/config.php` | absent | only `packages.php`, `services.php` |

Test stores: Mongo `vietfeed_test`, SQLite `:memory:`, Redis DB15 through the existing guards. Migration rehearsal used a temp SQLite file
under `/tmp/claude-1000` (deleted). No dev DB, Redis, container, `migrate`/`db:seed` against dev, or `.env` access.

### Known limitations

- Migration is only exercised on SQLite. On MySQL the `dropForeign` + `string()->change()` path is standard but untested here; after migration the DB no longer
  enforces the status set (the application validates it). Recommend a rehearsal on a MySQL copy before deploying.
- Inbox search has no index support (`LIKE %q%` on snapshot text; Mongo name regex capped at 500 ids). Fine for current scale; 9b revisits.
- A hidden parent comment hides its visible replies from other viewers (they are only reachable through the parent query).
- Editing a comment after reporting does not change the stored snapshot (intended); a hidden comment can still be edited by its author.
- Reporting from a deleted-author case cannot issue a sanction (no user), only dismiss/false_report.
- Browser acceptance (clicking through `docs/demo/moderation-demo.md`) was not performed; coverage is feature tests only.
- `docs/demo/moderation-demo.md` was not executed against a dev database; the seeder is exercised by a test on the isolated stores.

## Control decisions round 1

- Q1 accepted: `tests/Feature/ApiTokensTest.php` edits (pending-appeal fixture setup; `DELETE` -> `POST .../revoke`) are listed under Files changed; token/epoch assertions untouched; reason: only pending appeals can be decided and destroy was removed.
- Q2 applied: `ModerationService::revokeSanction` now also unhides every comment with `hidden_by_sanction_id` = that sanction (a comment hidden by a different sanction stays hidden); the revoke is visible in the case history/page ("Đã thu hồi"). Tests: `ModerationSanctionTest::test_revoke_unhides_comment_hidden_by_that_sanction_and_report_survives`, `test_revoke_keeps_hidden_a_comment_hidden_by_a_different_active_sanction`. Demo doc updated.
- Q3 accepted: rejected-report tests assert no `Report` row exists (count 0 after every rejection case, including hidden/deleted/banned/unverified/admin/self).
- Q4: migration uses only schema-builder operations plus two portable SQL statements (`UPDATE reports SET status`, correlated-subquery `UPDATE reports SET comment_* = (SELECT ...)`), no driver branches. Driver-dependent parts for MySQL rehearsal: `dropForeign(['comment_id'])` (its backing index remains), `string('status')->change()` from enum, `renameColumn`. Rollback is forward-only for FK/enum.
- Q5: browser acceptance left to control.


## Control decisions round 2 (MySQL rehearsal)

Control rehearsed the migration on MySQL 8.4: legacy scenario passed; two minor findings fixed.

- **M1 rerun safety.** `up()` now drops the `comment_id` FK only if `Schema::getForeignKeys('reports')` reports one on that column, renames
  `reviewed_at` only if it exists and `decided_at` does not, adds every new column (reports, sanctions, comments) only if missing, backfills
  snapshots only `WHERE comment_body IS NULL`, and marks legacy approved appeals revoked only where `revoked_at IS NULL`. No driver-specific
  branches.
- **M2 data consistency.** Legacy `reviewed` rows become `pending` with `admin_id` and the old `reviewed_at`/`decided_at` set to NULL
  (done before the rename so it works on either column name).
- **Test.** `ModerationEnforcementTest::test_repair_migration_is_rerunnable_and_cleans_legacy_reviewed_rows` runs `down()` then `up()` twice
  (plus `up()` twice in a row) on the SQLite test connection with legacy-shaped rows, asserting end-state schema, the reviewed row
  (pending, no admin, no decided_at, snapshot backfilled), an actioned row keeping its decision, an orphan report left without snapshot, and
  reports surviving comment deletion. The FK-present path was also re-checked on a throwaway legacy SQLite file (migrate, rollback, migrate: all exit 0, reviewed row cleaned); file deleted.

## Fix round (REVIEW-1)

| Item | Change | Test |
|---|---|---|
| R1 N+1 | `ArticleController::show` computes `canComment`/`canReport` once and passes them as props through `x-comment` / `x-comment-kebab` (including replies). | `ModerationEnforcementTest::test_article_page_sanction_queries_do_not_grow_with_comment_count` |
| R2 edit gap | `CommentController::update` refuses restricted users (mute/temp ban; perm ban hits the `/banned` redirect) and hidden comments, with a flash and no change. | `test_restricted_or_hidden_comment_cannot_be_edited_by_author` |
| R3 double appeal | `submitAppeal` is a conditional update (`appeal_status='none'`, active, not revoked); 0 rows changed means refusal. | `ModerationSanctionTest::test_second_appeal_submit_with_stale_model_does_not_overwrite_first_message` |
| R4 hide takeover | `hideComment` adds `where is_hidden = false`; a comment already hidden by another sanction keeps its owner. | `test_hiding_does_not_take_over_a_comment_already_hidden_by_another_sanction` |
| R5 locking | `lockPendingCase` locks all pending reports of the comment in id order; used by both `resolveCase` and `issueSanction`. `resolveCase` runs with 3 attempts and maps `QueryException` (deadlock/lock timeout) to a flash error. | `test_second_action_on_another_report_of_the_same_comment_is_refused_cleanly` (sequential simulation; real deadlock not reproducible on SQLite) |
| R6 down() | `down()` throws `RuntimeException` when any snapshot, revoke, appeal-decision or `hidden_by_sanction_id` data exists; works on empty data. | `test_repair_migration_is_rerunnable_and_cleans_legacy_reviewed_rows` (down on data throws and drops nothing; down/up after clearing evidence) |
| R7 appeal on case page | Case page result block shows the linked sanction's appeal status and decision note. | `test_case_page_shows_linked_sanction_appeal_status_and_decision_note` |
| R8 decision form | Form shows whenever the comment has pending reports and posts to the first pending report, not the URL report. | `test_case_page_decision_form_targets_pending_reports_regardless_of_url_report` |
| R12 notice | Author sees "Bình luận của bạn đã bị ẩn ..."; admin sees "... đang bị ẩn khỏi người dùng khác ...". | `test_hidden_notice_differs_for_author_and_admin` |

### Notes for 9b (review finding 9, no code change now)

Seam leaks to address when moving storage: SQL route-model binding in controllers; Eloquent relations used directly in views; `User::isBanned/isMuted/activeSanction` query SQL
directly; API-token hooks are bound to `Sanction` Eloquent events. Route these through `ModerationQueries`/service ports for 9b.

### Added known limitations (review NITs 10, 11, 13, 14)

- Token epoch/cleanup hooks run before the SQL commit (fails safe).
- Replies to a hidden comment are still accepted.
- Legacy orphan reports (no snapshot, comment gone) skip the author check when sanctioning.
- `ModerationDemoSeeder` resets the `mod-*` demo passwords on every run; keep it unwired from `DatabaseSeeder`.

Note on R1: the controller also passes the single `blockingCommentSanction()` snapshot to the view (the existing `ApiTokensTest` snapshot test requires exactly one blocking-sanction read per render); `canComment` is derived from it.

Verification (fix round): Moderation x3 green (59 tests / 636 assertions), `ApiTokensTest` green (70), full suite 300 tests / 4593 assertions exit 0, pint --test exit 0, WIP sha256 unchanged.

STATUS: **READY_FOR_REVIEW**

## Control acceptance (2026-11-17, Claude Opus 5.5 control)

Workflow: implementation by a Claude Sonnet worker; independent read-only review by a Claude Opus subagent (REVIEW-1.md,
PASS_WITH_NOTES, all actionable notes fixed in "Fix round (REVIEW-1)"); owner decisions 1–3 recorded in TASK.md.

### Final gate (control, independent run)

| Check | Result |
|---|---|
| `composer run test` | exit 0 — 300 tests / 4593 assertions (main 8cda49c: 241 / 3957) |
| `vendor/bin/pint --test` on all touched/new PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| Owner WIP sha256 | unchanged; unrelated files reformatted by a broad Pint run were restored (clean in git status) |

### MySQL 8.4 migration rehearsal (throwaway container, removed afterwards)

1. Legacy schema (all migrations except 2026_11_17_100001) with FK `reports.comment_id → comments` present, legacy rows
   (`reviewed` report with admin, `pending`, `actioned`, legacy approved appeal) → `migrate`: exit 0. `reviewed` → `pending` with
   `admin_id`/`decided_at` NULL; `actioned` keeps its decision; snapshots backfilled; approved appeal marked `revoked_via=appeal`;
   FK removed; `status` varchar(20) default `pending`; deleting a comment keeps all its reports.
2. `migrate:rollback --step=1` + `migrate`, twice: exit 0 each (after M1 fix; before it, rerun failed with 1091 on the FK drop).

### Browser acceptance (headless Chromium via Playwright, Claude Sonnet worker; screenshots reviewed by control)

Isolated stores (Mongo `vietfeed_test`, Redis DB15, throwaway SQLite), fixtures via tinker. All 11 steps PASS, no 500:
report + duplicate refusal; inbox grouping (1 row, 2 reports), status/reason filters and name/text/description search; case page
snapshot/state/reports/history/article link; temporary ban with hide → both reports actioned, comment hidden for others and
marked for the author; temp-banned author blocked from comment/edit/report (flash) and bookmark/boost (403), profile shows
sanction + appeal; single appeal (second refused, message unchanged); pending-appeal filter, approve → revoked by appeal, comment
visible again, case shows "Kháng cáo: Đã chấp nhận" + note; dismiss with note; mute from user page then revoke with note (who/when
shown, revoked filter); deleted comment keeps its report and the case shows "Bình luận đã bị xóa" + snapshot; permanent ban →
`/banned` everywhere with appeal form, appeal submitted. Cleanup: only started PIDs killed, throwaway files deleted, git status
identical to before, 0 secret patterns in `laravel.log`.

All Gate 9a acceptance items are verified. Gate 9b (Mongo/Redis/Cassandra moderation storage) starts from "Notes for 9b".
