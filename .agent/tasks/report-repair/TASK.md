# Gate 9a — Report repair and moderation acceptance (on current storage)

Canonical sources, in precedence order: `docs/specs/vietfeed-nosql-storylens.md` (§2 item 50, §7 stories 14 and 21, §11
"Moderation Module", §12.5, §16 item 5), `docs/planning/technical-gates.md` Gate 9, `docs/planning/project-scope-notes.md`
"Report and moderation", this folder's `RECON.md` (reproduced defects D1–D8 with probe evidence).

One gate part, one branch, one PR: branch `moderation/report-repair`, base `main` `8cda49c`.
Gate 9 is split by owner decision (2026-11-17): **9a** (this task) repairs the flow and pins expected behaviour with acceptance
tests on the current storage; **9b** (later) migrates case state to MongoDB, the review queue to Redis and audit history to
Cassandra, using 9a's tests as the regression net. Do not start 9b work here.

Goal: a user can report a comment, an admin can review the case, sanction (optionally hiding the comment) or dismiss it, and the
sanctioned user can appeal; every step works end to end in the browser and is proved by acceptance tests written from the
behaviour below, not from the current code. No moderation evidence is ever hard-deleted.

## Owner decisions (binding, 2026-11-17)

1. Hiding: the sanction form has an explicit "hide the reported comment" choice. A hidden comment is invisible to everyone except
   its author (who sees it marked as hidden) and admins. If an appeal on that sanction is approved, the comment is unhidden.
2. Evidence: no hard deletes of reports or sanctions. A report stores a snapshot of the comment body (and author id) at report
   time. Deleting a comment does not delete its reports. Admin "delete report" becomes "dismiss"; removing a sanction becomes
   "revoke" (inactive, kept in history with who/when/why).
3. Temporary ban: user can still read; blocked from commenting, bookmarking, boosting and submitting reports; may still view their
   sanction and appeal. Mute blocks commenting only. Permanent ban keeps the current `/banned` redirect (appeal still possible).

## Expected behaviour (acceptance contract)

Write one feature test per numbered rule (more where useful). Tests must fail on `main` for every rule tied to a defect D1–D8.

**User side**
1. An article page renders all visible comments (with author name, also for a comment whose author was deleted) and shows the
   report control to eligible users (D1).
2. A verified, non-sanctioned (or mute-only) user can report another user's comment with a reason from the fixed list and an
   optional description (≤ 2000). Success flash. The report is stored `pending` with the comment snapshot.
3. Rejected with a clear message and no record: reporting own comment; reporting twice the same comment; admins reporting;
   unverified users; temp/permanently banned users; reporting a hidden or deleted comment.
4. A sanctioned user sees the active sanction (type, reason, expiry) on the profile and can submit one appeal (10–2000 chars) per
   sanction while it is active; a second appeal or an appeal on someone else's sanction is refused.

**Admin side**
5. Reports inbox lists cases newest first, grouped by comment (count of reports per comment visible), filterable by status and
   reason, searchable by reporter name, comment snapshot text or description (D2, D3). Pagination keeps filters.
6. Case page shows the snapshot, the live comment state (visible / hidden / deleted), all reports on that comment, the author's
   sanction history, and links to the article when it still exists (no 500 when the article or comment is gone) (D2).
7. Statuses: `pending` → `actioned` (a sanction was issued from this case) | `dismissed` (no violation) | `false_report`
   (reporter abused the feature). Allowed transitions only from `pending`; reopening is not supported in 9a. Every decision
   records admin id, decided_at and an optional note. `reviewed` is removed (migrate existing rows to `pending`) (D7).
8. Issuing a sanction from a case: one action marks **all pending reports on that comment** `actioned`, links the sanction to the
   case, and hides the comment only when the admin chose to (D4). Sanctions can also be issued without a case (user page).
9. Dismiss / false_report likewise resolve all pending reports on that comment in one action.
10. Sanctions list: filter by type and status (active / expired / revoked / pending appeal), searchable by user name or reason
    without 500 (D3). Sanction page renders even if the linked comment or article is gone (D2).
11. Appeal review: approve → sanction inactive (revoked by appeal), comment unhidden if it was hidden by this sanction, case keeps
    `actioned` with the appeal outcome recorded; deny → sanction unchanged. Only `pending` appeals can be decided (D8).
12. Revoke sanction (replaces destroy): inactive, keeps record with admin/when/note; API-token revocation hooks keep working
    (existing `Sanction` model events and ApiTokensTest must stay green).
13. Comment deletion (by author or admin) keeps its reports and their snapshots; cases show "comment deleted" (D5, D6).

**Enforcement**
14. Mute: cannot comment. Temporary ban: cannot comment, bookmark, boost or report; can read, view profile/sanction, appeal.
    Permanent ban: redirected to `/banned` except logout and appeal. Expired sanctions stop applying without any job.

## Implementation constraints

- Storage stays as today (Comment/Report/Sanction on SQL, User/Article on MongoDB). Cross-store relations must not use
  `whereHas`/`withCount`/eager constraints across connections (laravel-mongodb cannot compile them; see Gate 7 C1): resolve ids
  in one query per store and join in PHP, batched per page (no N+1).
- Keep the logic in one Moderation module (e.g. `App\Moderation\…`) that controllers call, so 9b can swap storage behind it.
- Schema changes via new SQL migrations only (add columns: snapshot, decision note, decided_at, revoked_at/by/note, hidden_by
  sanction id, etc.; status enum change with data migration of `reviewed` → `pending`; drop the `comment_id` cascade in favour of
  keeping reports). Never edit old migrations. `is_hidden` must be set through an explicit, tested path.
- Blade only; reuse existing views/components and Vietnamese copy style. No new dependencies.
- RBAC permissions are a later gate: keep `isAdmin()`.

## Test isolation (hard requirements)

Mongo `vietfeed_test`, SQLite `:memory:`, Redis DB15 behind the live `getDbNum()` guard. No Cassandra or Neo4j needed.
Never touch dev data. Run suites sequentially.

## Deliverables

Code + migrations; `tests/Feature/Moderation*Test.php` covering rules 1–14; docs: a short moderation walkthrough in
`docs/demo/moderation-demo.md` (Vietnamese UI terms, demo accounts created by a test-only or demo seeder you add, never touching
owner WIP `database/seeders/SourceSeeder.php`); `GATE-REPORT-9a.md` in this folder.

## Non-goals

MongoDB/Redis/Cassandra moderation storage (9b), Neo4j abuse graph, RBAC, notifications/emails, reopening decided cases,
reporting Articles or users (comments only), auto-moderation/AI.

## Stop conditions

Product-scope change beyond the decisions above; need to edit owner WIP; a failure not fixable in scope; container restart or OOM.
