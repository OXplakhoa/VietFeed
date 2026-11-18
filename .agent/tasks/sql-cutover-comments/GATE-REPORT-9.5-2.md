# Gate 9.5 slice 2 — Comments on MongoDB

## Implementation

### Plan

Branch `cutover/comments-mongo` = main `8b4c3c3`. I recorded owner WIP hashes before any edit in
`/tmp/claude-1000/g95s2-wip.sha256`: 51 paths, meaning the 6 named WIP files plus every untracked file outside this folder.

**First attempt was blocked:** the stores were down (Docker Desktop not running), so the baseline gave exit 2 with 338 Mongo
connection errors. I made no code change and reported BLOCKED. The owner restarted Docker Desktop and control ran
`docker compose up -d` (volumes kept).
**Baseline (resumed):** `composer run test > /tmp/claude-1000/g95s2-baseline.log 2>&1; echo exit=$?` → **0**, 339 tests / 5803
assertions (matches main `8b4c3c3`).

- **Ids.** `Comment` → `MongoDB\Laravel\Eloquent\Model`, `mongodb` connection, `_id` primary key, `HasUuids` (UUIDv7 set in
  `performInsert`, so `withoutEvents()` fixtures also get ids; same as Report/Sanction). Explicit relation keys, `newRelatedInstance()`
  pin. Types are normalized on write: `user_id`/`article_id`/`parent_id`/`hidden_by_sanction_id` become string or null, and `is_hidden`
  becomes a bool. Defaults are `is_hidden:false`, `parent_id:null`, `hidden_by_sanction_id:null`, because Mongo has no column defaults.
  `is_hidden`/`hidden_by_sanction_id` stay non-fillable.
- **Indexes.** New idempotent migration `2026_11_18_200001_create_mongodb_comment_indexes` creates
  `{article_id, created_at:-1}`, `{parent_id}`, `{user_id}`, sparse `{hidden_by_sanction_id}`, `{created_at:-1}` and a unique partial
  index on `{legacy_sql_id}`. The `moderation_cases.comment_id` unique index (9b migration) is unchanged; it now holds strings.
- **Moderation touch points.** See the table below. The string id flows report → case (upsert on unique `comment_id`) → case and
  sanction events → stream → Cassandra `comment_ref`. No `(int)` casts of comment ids remain.
- **Hide/unhide.** These are single-document conditional updates on the comment. The F1 re-check and repair sweep are unchanged in
  logic.
- **Cassandra.** `ALTER TABLE … ADD comment_ref text` is idempotent. `comment_id bigint` stays for history and is written only from
  a legacy integer. The timeline exposes `comment` = `comment_ref ?? comment_id`.
- **Reply cascade.** A model `deleting` hook deletes all descendants breadth-first (cycle safe) before the comment itself. Reports
  are kept.
- **Import.** `comments:import-sql` (`App\Content\CommentSqlImport`) copies parents before replies (multi-pass), keeps an id map
  through `legacy_sql_id`, and remaps legacy refs in reports, cases and events (`legacy_comment_id` kept). It relinks legacy-integer
  hide links and is rerunnable.
- **Tests.** Baseline first, then a focused run after each step. Storage-only edits to existing tests, a new `CommentsMongoTest`,
  3× each changed file, then the full suite.

### Moderation touch points

| Location | Before (9b) | Now |
|---|---|---|
| `Report` | `comment_id` cast `integer` | cast removed; `setAttribute` stores `(string)` |
| `ModerationService::reportComment` | int `comment_id`, duplicate check by int | string id; unique `{reporter_id,comment_id}` still refuses duplicates |
| `ModerationCases::findByComment/ensureFor/register/recordHistory/attemptDecision` | `(int)` everywhere | `string`; the case upsert on unique `comment_id` is still the per-comment atomicity point |
| `ModerationEvents::make` | `comment_id ?int` | `comment_id ?string`; legacy integers survive only as `legacy_comment_id` (set by the import remap) |
| `ModerationAudit::bootstrap/apply/timeline` | `comment_id bigint` | `+ comment_ref text` (CREATE for new tables, `ALTER … ADD` tolerated for existing ones). A string id writes `comment_ref`. An integer or numeric string (legacy) writes `comment_id bigint`, as does `legacy_comment_id`. Timeline selects both and adds `comment`; the view renders `data-comment` |
| `ModerationService::applyHide` | SQL `where is_hidden=false` | `updateOne({_id:C, is_hidden:{$ne:true}}, {$set:{is_hidden:true, hidden_by_sanction_id:S}})`, read back, then the F1 re-check (revoked → unhide, `skipped`) **before** `applied` / COMMENT_HIDDEN |
| `finishUnhide` | SQL `where hidden_by_sanction_id=S` | `updateMany({hidden_by_sanction_id:S})` (per-document conditional) |
| `unhideComments`, `revokeEvents` | SQL pluck + per-id update | Mongo `find({hidden_by_sanction_id:S})`, per comment `updateOne({_id, hidden_by_sanction_id:S})`; one COMMENT_UNHIDDEN per modified doc |
| `sweepCommentsOfLiftedSanctions` (F1) | SQL distinct | Mongo `distinct('hidden_by_sanction_id', {≠ null})`, chunks of 500 |
| `ModerationQueries::rows` | `Comment::whereIn('id')` (SQL), `(int)` | one Mongo `whereIn('_id')` per page, string keys |
| `ModerationQueries::caseFor/sanctionDetail` | SQL `Comment::find` / `exists` | same calls, now Mongo |
| `SqlImport` (9b) | `(int)` comment ids | `(string)`: a legacy SQL id becomes a numeric string, which the comment import remaps. It still reads SQL `comments` for hide links (accepted, Q2) |
| `ReportController` | `ctype_digit` + `find((int))` | `Str::isUuid` + `find` (deleted comment still gets a message, not a 404) |

The 9a/9b rules still hold:
- Owner decision 1 / R4: hide only through moderation, with no takeover. The conditional `is_hidden ≠ true` is tested, and the
  fillable guard is tested on Mongo.
- Unhide affects only `hidden_by_sanction_id = S`.
- The F1 sweep is unchanged and tested with string ids.
- Rule 13: reports are kept on delete.
- The case-document protocol is untouched.

### Decisions

- **Leaf-first cascade.** Descendants are deleted before the parent. A crash leaves the parent in place, and a retried delete
  finishes the job. A reply is never orphaned.
- **Counts.** Home ranking, admin dashboard and the admin user list use `App\Support\MongoCounts::group(model, field, ?ids)`: one
  `$group` per request/page. Bookmarks and unlocks stay SQL batched. The dashboard uses `Comment::count()` and recent comments with
  eager `user`/`article`.
- **Admin comment search** keeps `LIKE` (laravel-mongodb regex), consistent with the other Mongo admin lists.
- **`parent_id` validation** → `nullable|string|exists:mongodb.comments,_id`. Behaviour is unchanged: the parent is not checked
  against the article, as before.
- **Numeric strings count as legacy.** A UUID is never all digits, so the import remap, the Cassandra column choice and
  `legacy_comment_id` all key on "integer or numeric string". This makes the 9b import (now strings) and 9b runtime data (integers)
  converge the same way.
- **A legacy reference to a comment deleted before the cutover** stays numeric (`refs_unmapped`). The case page shows "deleted",
  as before.
- **The import does not publish or reemit by itself.** The operator runs `moderation:audit reemit` afterwards (control Q3), and the
  command prints that hint.

### Test edits (setup / storage assertions only; no rule weakened)

| File | Edit | Reason |
|---|---|---|
| `tests/TestCase.php` | wipe `comments` in tearDown | Comment lives on Mongo (RefreshDatabase only covers SQL) |
| `tests/Concerns/ModerationFixtures.php` | `comment()` `?int $parentId` → `?string`; docblock | string comment ids |
| `ModerationAdminCaseTest` | `(int) $cases[i]->comment_id` → `(string)` | the inbox row's comment id is now the UUID string |
| `ModerationEnforcementTest` | the 9a SQL migration rerun test creates its legacy comment with `DB::table('comments')` and deletes it the same way | that test exercises the legacy SQL schema/backfill; `Comment` is on Mongo now |
| `ModerationNosqlTest` | `test_sql_import_is_idempotent_…`: legacy reports reference legacy **SQL** comments. After the two 9b import runs (assertions unchanged) it drains the consumer, runs `comments:import-sql` and `moderation:audit reemit`, then reads the imported Mongo comment | realistic legacy data and the documented cutover order (Q3). All 9b assertions kept, incl. `comments_relinked 1`, hide link, unhide on approve, timeline `ready` |
| `ApiTokensTest` | one `assertSame` → `assertEquals` on the comment raw-attribute snapshot | Mongo dates hydrate as new Carbon objects (same edit 9b made for the report snapshot). Token/epoch assertions untouched |

New `tests/Feature/CommentsMongoTest.php` (11 tests / 218 assertions):
- CRUD with UUIDv7 and string refs, parent validation, 404 on a non-UUID.
- Reply cascade at depth 2 with reports and snapshots kept (author and admin delete).
- Hide/unhide conditional updates: no takeover (`hide_state=skipped`), unhide only own sanction, F1 sweep idempotent, fillable guard.
- String id end to end: report → case → events → Cassandra rows with `comment_ref` and null bigint; timeline `comment`; case page
  `data-comment`.
- Timeline reads a legacy bigint row next to a `comment_ref` row; double `bootstrap()` works.
- Import: thread out of id order, hidden link via the legacy sanction id, 9b-import string refs and 9b-runtime int refs, deleted
  legacy ref, counts. Cutover drain → import → reemit: the Cassandra row keeps its bigint and gains `comment_ref`. The page renders
  the thread. Rerun is a no-op and the SQL table stays.
- Interrupted thread copy is finished by a rerun.
- SQL-proof: source scan plus an SQL query listener across comment, moderation and count pages.
- One grouped count per page.
- `DemoEngagementSeeder` idempotent on Mongo.
- Index migration idempotent.

### Files changed

- **New:** `app/Content/CommentSqlImport.php`, `app/Console/Commands/ImportSqlComments.php`,
  `database/migrations/2026_11_18_200001_create_mongodb_comment_indexes.php`, `tests/Feature/CommentsMongoTest.php`, this report.
- **Modified:**
  - Models: `app/Models/{Comment,Report,Article,User}.php`
  - Moderation: `app/Moderation/{ModerationService,ModerationCases,ModerationEvents,ModerationQueries,ModerationAudit,SqlImport}.php`
  - Support: `app/Support/MongoCounts.php`
  - Controllers: `app/Http/Controllers/{CommentController,ReportController,HomeController,Admin/UserController}.php`
  - Views: `resources/views/admin/partials/moderation-timeline.blade.php`
  - Tests: as listed above
  - Docs: `docs/database/nosql-local.md`, `docs/demo/moderation-demo.md` (mongosh example uses a string id)
- **Unchanged but now Mongo-backed:** `Admin\CommentController`, `Admin\DashboardController`, `ArticleController`,
  `Admin\ArticleController`, comment views, `DemoEngagementSeeder`, `ModerationDemoSeeder`.

### Verification

All commands were run in `/home/dolakhoa/code/VietFeed`, sequentially. Exit codes come from `cmd > log 2>&1; echo exit=$?`;
logs are in `/tmp/claude-1000/`.

| # | Command | Exit | Result |
|---|---|---|---|
| 0 | `composer run test` baseline (`g95s2-baseline.log`) | 0 | 339 / 5803 |
| – | step runs: `phpunit --filter Moderation` (`step1`) | 1 | 79/82: inbox `(int)` assertion, 9a SQL-migration fixture, 9b import fixture → test edits above |
| – | `composer run test` (`step4-full`) | 1 | 338/339: ApiTokens Carbon snapshot → `assertEquals` |
| 1 | `phpunit tests/Feature/CommentsMongoTest.php` ×3 | 0,0,0 | 11 / 218 each |
| 1 | `ModerationNosqlTest` ×3 | 0,0,0 | 23 / 446 each |
| 1 | `ModerationEnforcementTest` ×3 | 0,0,0 | 14 / 194 each |
| 1 | `ModerationAdminCaseTest` ×3 | 0,0,0 | 18 / 227 each |
| 1 | `ApiTokensTest` ×3 | 0,0,0 | 70 / 1454 each |
| 1/2 | `composer run test -- --display-all-issues` + `composer run test` ×2 (covers `TestCase.php` and `ModerationFixtures` 3×) | 0,0,0 | **350 tests / 6021 assertions** each; 0 deprecation/warning/notice/risky lines |
| 3 | `composer check-platform-reqs` | 0 | ok |
| 3 | `vendor/bin/pint` then `vendor/bin/pint --test` on the 25 touched PHP files (list `g95s2-pint-files.txt`) | 0 / 0 | Pint fixed only `app/Support/MongoCounts.php` and `tests/Feature/CommentsMongoTest.php`; the Blade partial was not reformatted |
| 4 | `grep -rnE "(DB::table\|->table)\(\s*['\"]comments['\"]\|exists:comments\b\|FROM\s+comments\b" app routes resources database/seeders` | 0 (matches) | only `app/Moderation/SqlImport.php:53,101` and `app/Content/CommentSqlImport.php:36`. The same rule plus a runtime SQL-listener check are asserted in `CommentsMongoTest::test_only_the_import_classes_read_sql_comments`. (The old 9a migration `2026_11_17_100001` backfill reads SQL comments at migrate time only; unchanged) |
| 5 | `sha256sum -c /tmp/claude-1000/g95s2-wip.sha256` | 0 | all 51 owner WIP paths unchanged |
| 5 | `git diff --check` | 0 | clean |
| 5 | `git status --short` | 0 | owner WIP `M` lines and untracked dirs as at start, plus the files listed above; `bootstrap/cache` only `packages.php`/`services.php` |

Stores touched: Mongo `vietfeed_test`, Redis DB15 (fixture guard), Cassandra keyspace `vietfeed_test` (tests delete their
partitions, including directly written ones), SQLite `:memory:`. I ran no dev migrate, seed or import, no FLUSHALL, and did not
start or restart any container. No staging or commits.

### Cutover order

Legacy data path, in a maintenance window:

1. `php artisan down`
2. Drain the audit consumer: run `php artisan moderation:audit consume --limit=100` until it returns 0. Events in flight still carry
   integer ids and would no longer match the remapped canonical copy.
3. `php artisan migrate --force` (comment indexes).
4. `php artisan moderation:import-sql`, only if 9b's import has not run yet. It must run **before** the comment import.
5. `php artisan comments:import-sql` (prints counts; rerunnable).
6. `php artisan moderation:audit schema` (adds `comment_ref`).
7. `php artisan moderation:audit reemit`.
8. `php artisan moderation:rebuild-queue`.
9. `php artisan up`.

Rerun `comments:import-sql` after any later `moderation:import-sql`.

**Dev choice:** fresh. Re-seed `DemoEngagementSeeder` (and `ModerationDemoSeeder`) instead of importing, and never mix the two
paths. Documented in `docs/database/nosql-local.md`.

### Known limitations

- **No transaction across thread delete.** An interrupted delete leaves the parent (and possibly some descendants). Retrying the
  delete finishes it; no repair command is needed.
- **Order dependency with the 9b import.** If `comments:import-sql` ran before `moderation:import-sql`, comments hidden by legacy
  sanctions get no COMMENT_HIDDEN backfill event. Their hide link is still relinked on the next comment import rerun. The documented
  order avoids this.
- **Legacy refs to comments deleted before cutover stay numeric** (`refs_unmapped`). `comment_ref` stays empty for them in Cassandra.
- **No new runtime query was tuned beyond the indexes.** Admin comment search is an unindexed regex, like the other Mongo admin
  lists.
- **No browser walkthrough was done** (feature tests only).

### Open questions for control

- None blocking. Q2 and Q3 were answered by control and are implemented as decided.

STATUS: **READY_FOR_REVIEW**

## Fix round (REVIEW-1)

Control decisions: M1 option (b); m1, m2 and n1 as requested. Before every suite I waited until
`ps -eo args | grep -c "[a]rtisan serve --host=127.0.0.1 --port=8077"` returned 0, so the parallel Terra browser run was never wiped.

| Item | Change | Test |
|---|---|---|
| **M1** | `app/Moderation/SqlImport.php` is back to main byte for byte except `unimported()` (m2). It writes and looks up legacy comment ids as **integers**. `ModerationEvents::commentRef()` keeps an integer as an integer and turns anything else into a string. `ModerationCases` passes the report's `comment_id` through unchanged (`findByComment(int\|string)`), so a 9b-shaped report keeps its int and a runtime report keeps its UUID. `comments:import-sql` is the only place that converts int or numeric-string refs. **Recovery merge** (`CommentSqlImport::mergeDuplicateCases`), applied to every legacy id whose cases exist under more than one key (`L`, `"L"`, UUID): (0) pick one survivor deterministically: one that already merged another, else the UUID-keyed one, else the int-keyed one, else the oldest. (0b) per reporter, archive duplicate reports into `reports_duplicates` (`duplicate_of`), keeping the survivor's copy. (1) one write on the survivor, guarded by `merged_case_ids ≠ D`: events re-keyed to the survivor (pending REPORTED events join the open round, others round 0), decisions kept as settled round-0 history, counters recomputed from the live pending reports, `version+1`. (2) move reports and sanctions (`case_id`, `audit_case_id`, `events.$[].case_id`, `orphaned_from_case`). (3) delete the empty duplicate. A claimed decision on either case means no merge, and the rename counts a `case_conflicts`. Renames and report remaps now run **per document** and catch `BulkWriteException` 11000 (case conflict → counted; report duplicate → archived), so a rerun converges | `ModerationNosqlTest::test_sql_import_merges_legacy_reports_into_an_existing_live_case`, meaning restored: the live 9b case is keyed by the int SQL comment id (new fixture `legacyKeyedReport`). Legacy SQL reports for the same int → `moderation:import-sql` (merged 1, rerun 0; unchanged 9b assertions) → `comments:import-sql`: exactly 1 case, `comment_id` = UUID, `legacy_comment_id` = int, 3 reports on the UUID, exit 0. Rerun is a no-op (remapped 0). `CommentsMongoTest::test_comment_import_merges_int_and_string_keyed_duplicate_cases_and_converges`: int case + `"L"` case (incl. the same reporter under both keys) → 1 case (survivor = int case), `merged_case_ids`, pending 2, severity 2, duplicate report archived with `duplicate_of`, all events re-keyed with the UUID, rerun no-op, one dismiss resolves both reports, timeline `ready` after reemit |
| **m1** | `ModerationAudit::addColumn()` is used for both ALTERs (`aggregate_version`, `comment_ref`). It tolerates only `/conflicts with an existing column\|already exists/` anywhere in the exception chain and rethrows everything else | `test_cassandra_schema_alter_only_tolerates_an_existing_column`: synthetic "conflicts" → tolerated; a real ALTER on an unknown table in `vietfeed_test` → rethrown; a client on port 1 → `bootstrap()` throws. The real "already exists" path runs in every setUp (`bootstrap()` on an existing table) |
| **m2** | `comments:import-sql` prints `hidden_unlinked` (comments whose `hidden_by_sanction_id` is still a numeric legacy id). `SqlImport::unimported()` returns `hidden_comments`. The `/admin/processing` warning block (`#moderation-legacy-warning`) shows `#comment-legacy-warning` | `test_comments_hidden_by_an_unimported_legacy_sanction_are_counted_and_warned` (warning shown, then sanction imported → `hidden_relinked 1`, `hidden_unlinked 0`, warning gone). The 9b warning test is unchanged and green |
| **n1** | Own unmerged migration edited: the partial index on `hidden_by_sanction_id` uses filter **`$gt: ""`** instead of control's suggested `$type: "string"`, because `explain` showed `{hidden_by_sanction_id: S}` doing a COLLSCAN with the `$type` filter (the planner cannot prove equality implies `$type`). The range filter is used (IXSCAN) and still excludes nulls. The migration drops a same-name index with other options (left by the earlier sparse revision, e.g. in `vietfeed_test`) before creating it | `test_comment_index_migration_is_idempotent` asserts the filter and an `explain` winning plan on `hidden_by_sanction_id_1` |

Other edits: `tests/TestCase.php` also wipes `reports_duplicates`. `tests/Concerns/ModerationFixtures.php` gains `legacyKeyedReport()` (test-only, the 9b-shaped fixture). In `CommentsMongoTest`'s import test, 9b-import refs are integers now (`findByComment($reply)`, `legacy-gone` = int 999). The docs (`nosql-local.md`) describe the index, the int rule, the merge, the ALTER tolerance and `hidden_unlinked`.

One incident, no dev access: the first CommentsMongoTest run errored in setUp on the old sparse index, before the test set its Cassandra keyspace. The tearDown guard (`assertSame('vietfeed_test', keyspace)`) then failed and correctly skipped cleanup. Mongo was `vietfeed_test` throughout, and `CassandraClient` refuses non-test keyspaces while testing.

### Verification (fix round)

| Command | Exit | Result |
|---|---|---|
| `vendor/bin/phpunit tests/Feature/CommentsMongoTest.php` ×3 | 0,0,0 | 14 / 279 each |
| `vendor/bin/phpunit tests/Feature/ModerationNosqlTest.php` ×3 | 0,0,0 | 23 / 457 each |
| `composer run test` ×3 (covers `TestCase.php`, `ModerationFixtures`) | 0,0,0 | **353 tests / 6093 assertions** each; 0 deprecation/warning/notice/risky lines |
| `composer check-platform-reqs` | 0 | ok |
| `vendor/bin/pint` + `pint --test` on the 25 touched PHP files (`g95s2-pint-files.txt`, Blade excluded) | 0 / 0 | no fixes needed |
| grep SQL `comments` readers in `app routes resources database/seeders` | – | only `app/Moderation/SqlImport.php:54,102` and `app/Content/CommentSqlImport.php:39` |
| `sha256sum -c /tmp/claude-1000/g95s2-wip.sha256` | 0 | 51/51 owner WIP unchanged |
| `git diff --check` | 0 | clean; `git status --short`: owner WIP as at start plus this slice's files (added `resources/views/admin/processing.blade.php`); `bootstrap/cache` only packages/services |

Known limitation added: a merged survivor's `reports_count` counts the reports that were live at merge time. Archived duplicates
stay in `reports_duplicates`, and their REPORTED events stay in the survivor's history.

STATUS: **READY_FOR_REVIEW** (fix round complete)

## Control acceptance (2026-11-18, Claude Opus 5.5 control)

Workflow: implementation Claude Opus (hard slice: moderation atomicity coupling); independent reviews Claude Opus subagents —
REVIEW-1.md FAIL (M1), fixed; REVIEW-2.md PASS_WITH_NOTES (MINOR/NIT only; runbook now runs `moderation:repair` before
`comments:import-sql`). Control accepted the worker's `reports_duplicates` archive (no hard delete) and the `$gt: ""` partial index.

| Check | Result |
|---|---|
| `composer run test` (control, after fix round) | exit 0 — 353 tests / 6093 assertions (main 8b4c3c3: 339 / 5803) |
| `pint --test` touched PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| SQL reads of `comments` | only `App\Moderation\SqlImport` and `App\Content\CommentSqlImport` (both removed in the final slice) |
| Owner WIP sha256 | unchanged |

Browser (GPT 6.1 Terra, headless Chromium, isolated Mongo `vietfeed_test` / Redis DB15 / Cassandra `vietfeed_test`), 6/6 PASS, no 500:
comment + nested reply + edit; two reports → one case; temporary ban with hide → hidden for reader, marked for author, Mongo
`is_hidden: true` + string comment ids on reports/case, Cassandra `comment_ref`, timeline COMMENT_HIDDEN; appeal approved →
visible again, COMMENT_UNHIDDEN; admin comments/dashboard/users counts; deleting the parent removes the reply, case keeps the
snapshot ("Bình luận đã bị xóa"). The run preceded the fix round, which only touched the import commands, the Cassandra schema
helper, the processing warning and the index migration — not the exercised runtime paths.

Dev cutover choice stays "fresh": re-seed DemoEngagement instead of importing legacy comments.
