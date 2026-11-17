# Gate 9b — Moderation on MongoDB, Redis review queue, Cassandra audit

## Implementation

### Plan

Baseline before any change (branch `moderation/nosql-storage` = `main` 55243cf): `vendor/bin/phpunit --filter Moderation` exit 0,
59 tests / 636 assertions; `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` exit 0, 70 tests / 1453 assertions.

**Module seam.** Controllers keep talking only to `App\Moderation\ModerationService` (commands) and `ModerationQueries` (reads).
New collaborators behind them: `ModerationCases` (case-document protocol: register, claim, confirm, release, settle, repair),
`ModerationQueue` (Redis projection), `ModerationAudit` (outbox publish, Redis Stream consumer, Cassandra projection, overview,
reemit), `ModerationEvents` (event envelope factory), `SanctionSnapshot` (per-request memo of a user's active sanctions).

#### MongoDB documents and indexes

All ids are UUIDv7 strings generated before insert (`_id`), set in `performInsert` so `withoutEvents()` fixtures also get them.
Cross-store references: `comment_id` int (Comment stays on SQL until 9.5), user/article/sanction/report ids strings.

| Collection | Shape | Indexes (migration `2026_11_18_000001_create_mongodb_moderation_indexes`, idempotent `createIndex`) |
|---|---|---|
| `reports` | 9a columns (reporter_id, comment_id, reason, description, status, comment_body/user_id/article_id snapshot, admin_id, decided_at, decision_note, sanction_id) + `case_id`, `legacy_sql_id` | unique `{reporter_id,comment_id}` (duplicate guard), `{comment_id,status}`, `{case_id}`, `{status,created_at}`, `{sanction_id}`, unique partial `{legacy_sql_id}` |
| `sanctions` | 9a columns + `case_id` (nullable), `audit_case_id`, `version`, `events[]` (outbox), `unhide_pending`, `legacy_sql_id` | `{user_id,is_active,created_at}`, `{appeal_status}`, `{created_at}`, `{revoked_at}`, `{audit_case_id}`, sparse `{unhide_pending}`, unique partial `{legacy_sql_id}` |
| `moderation_cases` | one per comment: `comment_id`, `status` (`none`/`pending`/`actioned`/`dismissed`/`false_report`), `round`, `version`, `pending_count`, `reports_count`, `severity`, `round_started_at`, `last_reported_at`, snapshot ids, `decisions[]` (`op_id`, `round`, `from_round`, `status`, `state` claimed/confirmed, `sanction_id`, `hide`, `hide_state`, `settled`, admin/note/time, `snapshot` of round counters), `events[]` (outbox) | unique `{comment_id}`, `{status,pending_count,severity,round_started_at}` (queue fallback order), `{decisions.state}`, `{updated_at}` |

A *round* is the set of reports pending at one decision. A report on a decided comment starts a new round (9a behaviour: a new
report on a closed comment is pending again; decided reports keep their status; reopening a decision is not possible).

#### Case-document atomicity protocol (no transactions)

Only one document is ever written conditionally per step; every later step is idempotent and re-derivable from the case document,
so a retry or `moderation:repair` converges. SQL locks of 9a map as follows.

| 9a rule (SQL lock) | Single-document operation | Crash between writes → state → convergence |
|---|---|---|
| Report (rule 2/3) | Insert report (unique `{reporter_id,comment_id}` = duplicate refusal, no record), then one `findOneAndUpdate` on the case guarded by `events.report_id ≠ id` that `$inc`s counters, starts a new round when the case is not pending, and appends `REPORTED` | Report inserted but not registered → case does not list it; `repair` registers every report missing from `events` (guard makes it idempotent) |
| Dismiss / false report (rules 7, 9; `lockPendingCase`) | Conditional update `{status:'pending', round:R, no claimed decision}` → status, confirmed decision `{round R, from_round}`, `version+1`, `CASE_DISMISSED`/`CASE_FALSE_REPORT`. 0 matched = someone decided first → refused, nothing written | Case decided, report docs still `pending` → `settle` updates `{_id ∈ REPORTED ids of rounds from..R, status:'pending'}`; idempotent, run at the end of the action, on the case page, and by `repair` (decision `settled:false`) |
| Sanction from a case (rule 8; lock + insert) | (1) *claim*: same conditional filter → status `actioned`, decision `state:claimed, sanction_id:S` (S generated first). (2) insert sanction S (Eloquent; token hooks run). (3) *confirm*: `{decisions.op_id, state:claimed}` → `confirmed`. (4) settle reports. (5) hide comment (SQL `where is_hidden=false`) only if requested and S is still unrevoked; read back `hidden_by_sanction_id` → `COMMENT_HIDDEN` on S; re-check S and unhide if it was revoked meanwhile | Insert fails (e.g. token epoch pre-bump) → *release* the claim (restores round counters from the claim snapshot), nothing else written. Crash after (1): claim older than the lease (10 min) → `repair` releases if S does not exist, confirms if it does (S's existence is the decision). Crash after (3)/(4): `settled:false` / `hide_state:requested` → `repair` finishes. A second claim while one is in flight is refused ("đang bận", same as a 9a lock timeout) so rounds never interleave |
| Appeal submit (rule 4, R3) | `{_id, user_id, appeal_status:'none', is_active, revoked_at:null, not expired}` → pending, `version+1`, `APPEAL_SUBMITTED` | Single write; 0 matched = refused, first message kept |
| Appeal decide (rule 11; `lockForUpdate`) | `findOneAndUpdate {appeal_status:'pending'}` pipeline: outcome; on approve and not yet revoked also revocation fields + `unhide_pending:true`; `APPEAL_*` (+`SANCTION_REVOKED`) | Revoked but comment still hidden → `unhide_pending` → unhide `where hidden_by_sanction_id=S`, `COMMENT_UNHIDDEN`, unset flag; `repair` redoes it |
| Revoke (rule 12) | `findOneAndUpdate {revoked_at:null, is_active:true}` → revocation, `unhide_pending:true`, `SANCTION_REVOKED` | Same as above |
| Hide / unhide (owner decision 1, R4) | SQL conditional updates on `comments` (`is_hidden=false` / `hidden_by_sanction_id=S`), marker on the Mongo doc after | Idempotent SQL; the marker is re-derived by reading the comment |

API-token hooks: creation still goes through Eloquent `saving/saved` (pre-bump aborts before insert; post-bump + cleanup after).
Conditional updates that lift a ban (revoke, approved appeal) call the same `Sanction::afterBanTransition()` after the Mongo write.

#### Redis review queue (projection)

`nosql` connection. `moderation:queue:v1` sorted set (member = case id), `moderation:queue:v1:meta` hash (`count`, `built_at`),
`moderation:counters:v1` hash (`pending_appeals`), lock `moderation:queue:v1:rebuild-lock`. Score =
`(pending_reporters·4 + severity)·2^32 + (2^32−1 − round_started_at_seconds)`, read with `ZREVRANGE` → distinct reporters desc,
severity (hate_speech/harassment/sexual_content = 2 > misinformation = 1 > spam/other = 0) desc, oldest first. Sync per case via a
Lua script that is a no-op when `meta` is missing and keeps `meta.count` equal to the set size; a read is valid only when `meta`
exists and `count == ZCARD` (detects LRU eviction of either key). Invalid/failed → MongoDB query with the same order (index above)
and a lazy rebuild under a `SET NX EX` lock. Rebuild = Mongo snapshot → atomic replace → re-sync cases updated since the snapshot
started. Stale members (case no longer pending) are dropped on read. Pending appeals counter is recomputed from Mongo after each
appeal transition (convergent, no drift).

#### Cassandra audit

`moderation_events_by_case (case_id uuid, occurred_at timestamp, event_id uuid, type text, actor_id text, case_version bigint,
aggregate_type text, aggregate_id text, note text, report_id text, sanction_id text, comment_id bigint, PRIMARY KEY ((case_id),
occurred_at, event_id)) WITH CLUSTERING ORDER BY (occurred_at ASC, event_id ASC)`. Events are written into the Mongo case or
sanction document (`events[]`, same atomic update as the state change), then published to Redis Stream `moderation:events:v1`
(group `moderation-audit-v1`, DLQ `moderation:dead:audit:v1`, errors `moderation:errors:audit:v1`) through the existing
`StreamConsumer`. The projector rejects events that do not match the canonical Mongo copy and `INSERT`s by primary key, so
duplicates and replays are idempotent. Publish failures never fail the action (logged without payload); `moderation:audit reemit`
republishes canonical events. Case page shows "Lịch sử quyết định" from Cassandra, or "đang chờ đồng bộ" when Cassandra is
unreachable or has fewer rows than the canonical events.

**No-case sanction partition:** synthetic case id = the sanction's own UUIDv7 (`audit_case_id`). Each standalone sanction is its
own decision chain (issue → appeal → decision/revoke), so "chuỗi quyết định của một sanction case" is one partition read;
deterministic with no lookup table, and partitions stay small (a per-user partition would mix unrelated chains and grow forever).
Case-linked sanctions use the case id, so the case timeline includes appeal/revoke.

#### Data move `moderation:import-sql`

Reads SQL `reports`/`sanctions` (the only SQL reader of those tables). Sanctions then reports inserted raw (no token hooks: those
ran when the SQL rows were created) with `legacy_sql_id` (unique) so reruns skip; sanction/report ids remapped; case documents
built per comment from its legacy reports (one confirmed decision per distinct decided outcome, pending reports form the open
round) with backfill events; sanction events derived from timestamps; `comments.hidden_by_sanction_id` rewritten from legacy int to
the new UUID (column widened by migration `2026_11_18_000002`); queue rebuilt; audit events published; counts printed.

#### Test plan

All 9a suites stay as the regression net (edits only for storage assertions/setup, each listed). New
`tests/Feature/ModerationNosqlTest.php` (Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`): double resolve and
resolve-vs-sanction sequential simulation, claim release on failed insert, crash simulations + repair, queue ordering vs Mongo
fallback, eviction of each key + rebuild command + lazy rebuild, audit projection (duplicate delivery, replay from DLQ, reemit),
Cassandra-down path, no-case sanction partition, data-move idempotency, API-token hooks on revoke/approve.

### Atomicity protocol: rule → operation → crash/retry → test

All tests are in `tests/Feature/ModerationNosqlTest.php` unless named otherwise.

| Rule | Single-document operation | Crash / retry outcome | Test |
|---|---|---|---|
| Report once (2, 3) | insert under unique `{reporter_id, comment_id}`; `findOneAndUpdate` on case guarded by `events.report_id ≠ id` | insert without registration → `repair` registers once (`registered: 1`, rerun `0`) | `test_duplicate_report_is_refused_by_the_unique_index_without_double_counting`, `test_repair_converges_every_interrupted_step` (e) |
| Dismiss / false report once (7, 9) | `{status:pending, version:v, no claim}` → decision + event | decided but reports unsettled → settled by case page GET / `repair` | `test_double_resolve_of_the_same_case_produces_exactly_one_outcome`, `test_repair_…` (c), 9a `test_dismiss_*`, `test_only_pending_cases_can_be_decided_*` |
| Sanction from case vs other decision (8, R5) | claim → insert S → confirm → settle → hide | claim refused for a concurrent decision (`BUSY`); stale claim: released if S absent, confirmed if S exists | `test_resolve_while_a_sanction_claim_is_in_flight_is_refused_and_one_outcome_wins`, `test_repair_…` (a)(b), 9a `test_second_action_on_another_report_of_the_same_comment_is_refused_cleanly` |
| Insert failure leaves no decision (ApiTokens fail-safe) | release the claim (round counters restored from snapshot, reports that arrived meanwhile merged) | release is idempotent | `test_failed_sanction_insert_releases_the_claim_and_reports_arriving_meanwhile_are_kept`, `test_real_insert_failure_during_sanction_from_case_releases_claim_with_no_sanction`, `ApiTokensTest::test_admin_ban_mongo_failure_aborts_*`, `test_admin_ban_sql_failure_*` |
| Hide only by moderation path, no takeover (owner 1, R4) | SQL `where is_hidden=false`, read back, marker `hide_state` first-writer-wins, re-check revoke | `hide_state:requested` finished by `repair` | `test_repair_…` (b), 9a `test_hiding_does_not_take_over_*`, `test_comment_hiding_only_through_moderation_path` |
| One appeal (4, R3) | `{appeal_status:none, active, not revoked}` + version | single write | 9a `test_second_appeal_submit_with_stale_model_*`, `test_appeal_decisions_and_revoke_apply_exactly_once` |
| Appeal decided once / revoke once (11, 12) | optimistic `{appeal_status:pending|revoked_at:null, version:v}`; events + `unhide_pending` in the same write | `unhide_pending` → `repair` unhides | `test_appeal_decisions_and_revoke_apply_exactly_once`, `test_repair_…` (d), 9a `test_approved_appeal_*`, `test_revoke_*` |
| Token hooks (12, outcome 4) | Eloquent `saving/saved` on create; `afterBanTransition(-1)` after the Mongo write on revoke/approve | refused revoke does not bump | `ApiTokensTest` (70, token/epoch assertions unchanged), `test_ban_from_case_revoke_and_appeal_keep_token_hooks_and_order` |

### 9a / existing test edits (setup and storage assertions only; no rule weakened)

| File | Edit | Reason |
|---|---|---|
| `tests/TestCase.php` | wipe `reports`, `sanctions`, `moderation_cases` in tearDown | they now live on Mongo (same pattern as users/articles) |
| `tests/Concerns/ModerationFixtures.php` | `setUpModerationFixtures()`: isolate + flush Redis DB15 via the existing guard | queue/stream keys must not leak between tests |
| `ModerationAdminCaseTest` | `(int) $r->sanction_id` / `hidden_by_sanction_id` → `(string)`; per-row query counter also enables the Mongo command subscriber | UUIDv7 sanction ids; reports are Mongo commands now (counter would otherwise see no report reads) |
| `ModerationSanctionTest` | R4 `(int) … hidden_by_sanction_id` → `(string)` | UUIDv7 sanction ids |
| `ModerationEnforcementTest` | 9a migration rerun test reads `DB::table('reports')` instead of `Report::`; R1 counter enables the Mongo subscriber and now asserts exactly 1 read per request | `Report` is Mongo, the test exercises the legacy SQL table; strengthened so the counter cannot be vacuous |
| `ApiTokensTest` | `assertSame` → `assertEquals` for two raw-attribute snapshots; SQL trigger → Mongo `collMod` validator to make the sanction insert fail; snapshot test observes the Mongo `find` on `sanctions` and lifts via the Mongo table | Mongo dates hydrate as new Carbon objects; sanctions insert into Mongo; same single-read intent. Token/epoch assertions untouched |
| `CatalogArticlePagesTest` | expected per-request split `[7(+1) SQL, 2 Mongo]` → `[6(+1), 3]` | the one sanction-check read moved from SQL to Mongo (total unchanged) |

### Decisions and why

- **Inbox default order stays newest-first** (9a rule 5 and its unchanged test, which has an older 2-reporter case below a newer
  1-reporter case). The Redis priority order is a second tab `?view=queue` ("Hàng đợi ưu tiên"); the "N vụ chờ duyệt" and
  "N kháng cáo" badges read the Redis counters. See open question Q1.
- Badge now counts pending *cases* (Redis ZCARD), previously pending *reports*.
- `report_id` validation is `exists:mongodb.reports,_id`; route parameters are bound by id through `ModerationQueries`
  (`findReport`/`findSanction`, UUID check, 404).
- Event `note` carries admin notes / sanction reason (≤ 280 chars); appeal messages are not copied into the audit.
- `comments.hidden_by_sanction_id` widened to `string(36)` (new migration, forward-only `down()`).

### Files changed

New: `app/Moderation/{ModerationCases,ModerationQueue,ModerationAudit,ModerationEvents,SanctionSnapshot,SqlImport}.php`,
`app/Console/Commands/{ModerationRebuildQueue,ModerationRepair,ModerationAuditCommand,ModerationImportSql}.php`,
`database/migrations/2026_11_18_000001_create_mongodb_moderation_indexes.php`,
`database/migrations/2026_11_18_000002_widen_comments_hidden_by_sanction_id.php`,
`resources/views/admin/partials/moderation-timeline.blade.php`, `tests/Feature/ModerationNosqlTest.php`, this report.

Modified: `app/Moderation/{ModerationService,ModerationQueries}.php`, `app/Models/{Report,Sanction,User}.php`,
`app/Http/Controllers/{SanctionAppealController,Admin/ReportController,Admin/SanctionController,Admin/ProcessingController}.php`,
`app/Providers/AppServiceProvider.php`, `config/nosql.php` (`moderation_retry_ms`), views `admin/reports/{index,show}`,
`admin/sanctions/show`, `admin/processing`, `profile/_sanctions`; tests listed above; docs `docs/demo/moderation-demo.md`,
`docs/database/nosql-local.md`. Unchanged but now Mongo-backed through the module: `CheckUserSanction`, `CommentController`,
`ArticleController`, `routes/web.php` (`/banned`), `ModerationDemoSeeder`, `Comment::reports()`.

### Verification

All commands in `/home/dolakhoa/code/VietFeed`, run sequentially; exit codes are those of the real command (`cmd > log 2>&1; echo exit=$?`).

| # | Command | Exit | Result |
|---|---|---|---|
| 0 | baseline `vendor/bin/phpunit --filter Moderation` / `tests/Feature/ApiTokensTest.php` (before changes) | 0 / 0 | 59 / 636; 70 / 1453 |
| 1 | `vendor/bin/phpunit --filter Moderation` ×3 | 0, 0, 0 | 74 tests / 1071 assertions each (59 9a + 15 new) |
| 1 | `vendor/bin/phpunit tests/Feature/ModerationNosqlTest.php` ×3 | 0, 0, 0 | 15 / 316 each |
| 1 | `vendor/bin/phpunit tests/Feature/ApiTokensTest.php` ×3 | 0, 0, 0 | 70 / 1454 each |
| 1 | `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` ×3 | 0, 0, 0 | 16 / 308 each |
| 1 | `vendor/bin/phpunit tests/Feature/StoryPageTest.php` ×3 (processing controller touched) | 0, 0, 0 | 13 / 343 each |
| 2 | `composer run test` | 0 | **315 tests / 5029 assertions** (main 55243cf: 300 / 4593); 0 deprecation/notice/warning/risky lines |
| 3 | `composer check-platform-reqs` | 0 | ok |
| 3 | `vendor/bin/pint` then `vendor/bin/pint --test` on the 31 touched/new PHP files (Blade excluded) | 0 / 0 | Pint fixed only 3 new files of mine; no unrelated file touched |
| 4 | `grep -rnE "DB::table\('(reports|sanctions)'\)" app routes resources database/seeders` | – | only `app/Moderation/SqlImport.php:31-32`; `Report`/`Sanction` declare `$connection = 'mongodb'`; all raw collections go through `getMongoDB()->selectCollection`; no `Report $report`/`Sanction $sanction` binding left in `app/Http` |
| 5 | `php artisan route:list --path=admin/reports` / `admin/sanctions` / `admin/processing` | 0 / 0 / 0 | reports index/show/update; sanctions index/store/show/update + `POST {sanction}/revoke`; processing index + `POST retry` (section `moderation` added) |
| 6 | `sha256sum -c /tmp/claude-1000/gate9b-wip.sha256` (50 owner WIP files) | 0 | all OK |
| – | `bootstrap/cache` | – | only `packages.php`, `services.php` |

Stores touched: Mongo `vietfeed_test`, Redis DB15 (live `getDbNum()` guards in fixtures and in `ModerationQueue`), Cassandra
keyspace `vietfeed_test` (`CassandraClient` guard; the new test deletes its partitions), SQLite `:memory:`. No dev DB/keyspace/Redis
DB, no `migrate`/seed/new artisan command against dev (only `php artisan list`/`route:list`), no container restart, no config change
to Redis/Mongo/Cassandra servers. Cassandra "down" is simulated by pointing the client at port 1 in test config only.

### Known limitations

- Inbox search (`$regex` on snapshot/description, name lookup capped at 500 ids) has no text index; unchanged from 9a.
- Reports per comment and events per case/sanction are arrays in one document (bounded by distinct reporters/decisions; 16 MB
  document limit far away at this scale).
- A claim abandoned by a crash blocks new decisions on that case until the 10-minute lease passes and `moderation:repair` (or the
  next action on the case) resolves it; a sanction request slower than the lease could be released by repair and then insert its
  sanction anyway (sanction exists, case pending again) — admins would see a pending case next to the sanction.
- Redis queue ties within the same second are ordered by member id (Redis) vs `_id` (Mongo fallback).
- Lazy rebuild runs inline on a read that finds the projection invalid (guarded by a 30 s `SET NX` lock).
- The 9a fixture that sets a report status directly (`forceFill(['status' => 'dismissed'])`) leaves its case document pending; the
  inbox (report-level, like 9a) is correct, the priority queue would list it until a decision. Only reachable by direct writes.
- `moderation:audit schema` must be run once per environment (explicit, like `storylens schema`); no automatic consumer daemon is
  added (same operating model as the Story pipeline).
- Import of legacy decided reports into an already-live case records them as history (`round 0`) without counting them pending.
- MySQL `->change()` of `comments.hidden_by_sanction_id` exercised only on SQLite; recommend a rehearsal on a MySQL copy.
- No browser walkthrough was performed (feature tests only).

### Open questions for control

- **Q1 (product):** TASK outcome 5 says "the inbox default order reads the queue", but 9a rule 5 and its unchanged acceptance test
  require newest-first by default. Implemented: default newest-first, priority order on the "Hàng đợi ưu tiên" tab; badges from
  Redis. Switch the default if the owner prefers (one-line change in `Admin\ReportController::index` plus that 9a test).
- **Q2:** Pending badge now counts cases instead of reports ("N vụ chờ duyệt"). Confirm wording.
- **Q3:** Should a consumer for `moderation:audit consume` be added to `composer run dev` / scheduler? Left manual like StoryLens.

STATUS: **READY_FOR_REVIEW**

## Fix round (REVIEW-1)

Control decisions recorded: Q1 newest-first default + separate priority tab ACCEPTED; Q2 "N vụ chờ duyệt" ACCEPTED; Q3 audit
consumer (and repair) stay manual, documented, Gate 10 runbook wires them. **MySQL 8.4 rehearsal by control** of
`2026_11_18_000002_widen_comments_hidden_by_sanction_id`: column becomes `varchar(36)`, legacy value `7` and the index kept,
rollback + re-migrate exit 0.

| Item | Change | Test (`ModerationNosqlTest` unless noted) |
|---|---|---|
| F1 / MINOR-1 + NIT-4 | `applyHide` re-reads the sanction after the SQL hide and, if revoked/inactive, unhides (COMMENT_UNHIDDEN) and marks `hide_state=skipped` **before** any `applied` marker; the early "already lifted" branch also unhides (covers a crash between hide and re-check). `moderation:repair` sweeps comments whose `hidden_by_sanction_id` points to a revoked/inactive sanction (`unhidden_revoked`). `unhideComments` now unhides per comment first and records one COMMENT_UNHIDDEN only when a row changed | `test_repair_unhides_comments_left_hidden_by_a_revoked_sanction` (both `requested` and `applied` crash states, idempotent rerun) |
| F2 / MINOR-2 | `issueSanction` → `confirmOrReclaim`: confirm, or accept a confirm done by repair, else re-claim the still-pending case for S (then confirm/settle/hide), else flag S as orphan (`moderation_cases.orphaned_sanction_ids`, `sanctions.orphaned_from_case`, warning log). Case page shows "Biện pháp xử lý không gắn với quyết định của vụ việc" with links; sanction page shows a notice. Repair flags unreferenced case sanctions older than 60 s and prints `orphaned_new` / `orphaned_total`. Minimum lease 60 s: `ModerationService::repair()` clamps, `moderation:repair --lease` rejects < 60 (exit 1) | `test_request_stalled_past_the_lease_reclaims_the_case_or_flags_an_orphan` (stall injected in `Sanction::creating`: travel 11 min + repair releases; variant reclaim, variant dismissed-meanwhile → orphan visible on case page and in repair output; exactly 2 sanctions for 2 requests; `--lease=0` exit 1), `test_repair_flags_case_sanctions_that_no_decision_references`, `test_resolve_while_a_sanction_claim_…` (lease clamp) |
| F3 / MINOR-3 | Cutover order documented (down → migrate → import-sql → rebuild-queue → up) in `docs/demo/moderation-demo.md` and `docs/database/nosql-local.md`; `SqlImport::unimported()` (SQL ids without a `legacy_sql_id` copy) drives a warning on `/admin/processing` | `test_processing_page_warns_about_unimported_legacy_rows_even_when_story_overview_fails` |
| F4 / MINOR-4 | Queue sync is version-aware: `meta` keeps `v:<case id>` (also as tombstone after ZREM); Lua ignores a write older than the applied version; stale-read removal passes the read version; rebuild writes versions for pending cases and tombstones for cases updated in the last 24 h. `pendingCases()` = ZCARD only when `count == ZCARD` | `test_out_of_order_queue_sync_is_ignored_by_version` (late ZADD after ZREM, also after a rebuild) |
| F5 / MINOR-5 | Rebuild builds `tmp` + `tmp-meta`, then `swap($expected, $expectedFields)` publishes only if `ZCARD(tmp)` and `HLEN(tmp-meta)` match; otherwise it deletes tmp keys **and meta**, so reads stay on MongoDB; rebuild throws (command exit 1, lazy rebuild logs) | `test_rebuild_swap_aborts_when_the_temporary_set_is_incomplete` |
| F6 / MINOR-6 | `ModerationCases::decide()` split into a read loop + `attemptDecision($case, …)` (one conditional write against the case as read) | `test_stale_version_conditional_case_write_is_refused` (read, another report bumps version, write refused with nothing changed; `decide` then covers both reports) |
| NIT-2 | Event envelope has `aggregate_version` (document that recorded it) and `case_version` (case events: same; case-linked sanction events: the case version at write time; no-case sanctions: null). Cassandra gains `aggregate_version bigint` (`CREATE … IF NOT EXISTS` + idempotent `ALTER TABLE … ADD` for existing tables); timeline shows "vụ việc vN" or "biện pháp vN" | assertions in `test_cassandra_down_never_blocks_actions_…` |
| NIT-3 | `register()` only counts pending reports; decided ones go to `recordHistory()` (REPORTED round 0, no reopening); import merge reuses it | `test_registering_a_decided_report_records_history_without_reopening` |
| NIT-5 | `ProcessingController` loads the moderation overview and legacy check independently of the Story overview; store outages there log at **notice** (keeps StoryPageTest's exact warning count), other errors are reported | `test_processing_page_warns_…` (Redis down: Story "unavailable", moderation section + legacy warning still rendered); `StoryPageTest` unchanged and green |
| NIT-1 | Tie-break difference Redis vs Mongo fallback left documented (known limitations) | – |

Known limitations update: the only runtime SQL read of `reports`/`sanctions` besides the import is `SqlImport::unimported()` on
`/admin/processing` (cutover health check, removed with the tables in 9.5). Version tombstones live in `meta`; if `meta` is
evicted, the next read rebuilds anyway.

### Verification (fix round)

| # | Command | Exit | Result |
|---|---|---|---|
| 1 | `vendor/bin/phpunit --filter Moderation` ×3 | 0, 0, 0 | 82 tests / 1199 assertions each |
| 1 | `vendor/bin/phpunit tests/Feature/ModerationNosqlTest.php` ×3 | 0, 0, 0 | 23 / 444 each |
| 1 | `ApiTokensTest` ×3 / `StoryPageTest` ×3 / `CatalogArticlePagesTest` ×3 | all 0 | 70/1454, 13/343, 16/308 |
| 2 | `composer run test` | 0 | **323 tests / 5157 assertions**; 0 deprecation/notice/warning/risky lines |
| 3 | `composer check-platform-reqs` | 0 | ok |
| 3 | `vendor/bin/pint` + `--test` on the 31 touched PHP files | 0 / 0 | Pint fixed only `app/Moderation/ModerationEvents.php` |
| 4 | `grep -rnE "DB::table\('(reports|sanctions)'\)" app routes resources database/seeders` | – | only `SqlImport.php:32-33` (+ `unimported()` via `DB::table($table)`) |
| 6 | `sha256sum -c /tmp/claude-1000/gate9b-wip.sha256` | 0 | all 50 owner WIP files unchanged; `bootstrap/cache` only packages/services |

STATUS: **READY_FOR_REVIEW** (fix round complete)

## Control acceptance (2026-11-18, Claude Opus 5.5 control)

Workflow: implementation by a Claude Opus worker; independent read-only review by a separate Claude Opus subagent (REVIEW-1.md,
PASS_WITH_NOTES; MINOR-1..6 and NIT-2/3/5 fixed in "Fix round (REVIEW-1)", NIT-1 documented). Queue priority rule owner-confirmed.

### Final gate (control, independent run)

| Check | Result |
|---|---|
| `composer run test` | exit 0 — 323 tests / 5157 assertions (main 55243cf: 300 / 4593) |
| `vendor/bin/pint --test` on all touched/new PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| SQL reads of `reports`/`sanctions` | only `App\Moderation\SqlImport` (import + the cutover warning it serves) — to be removed in Gate 9.5 |
| Owner WIP sha256 | unchanged |

### MySQL 8.4 rehearsal (throwaway container, removed)

Schema through 9a + a comment hidden by legacy sanction id 7 → `migrate` (9b): `hidden_by_sanction_id` becomes `varchar(36)`,
value `7` and index kept; rollback + re-migrate exit 0.

### Browser acceptance (headless Chromium, Claude Sonnet worker; screenshots reviewed by control)

Isolated stores verified before running (Mongo `vietfeed_test`, Cassandra keyspace `vietfeed_test`, Redis nosql DB15). 9/9 PASS:
newest-first inbox + "N vụ chờ duyệt" badge; priority tab order matches Redis `ZREVRANGE` and the owner rule; queue keys deleted →
same order served from MongoDB, lazy rebuild and `moderation:rebuild-queue` restore it; sanction + hide → timeline REPORTED ×2,
SANCTION_ISSUED, COMMENT_HIDDEN after `moderation:audit consume` ("Đang chờ đồng bộ" before); appeal approve → APPEAL_SUBMITTED,
APPEAL_APPROVED, SANCTION_REVOKED, COMMENT_UNHIDDEN, comment visible again; dismiss → CASE_DISMISSED, badge 0; audit-pending state
then complete after consume; processing page stream stats, legacy warning appears for an unimported SQL row and disappears after
`moderation:import-sql`, rerun imports nothing; mongosh counts and `moderation:audit timeline --case=<id>` match the UI. Cleanup:
only started PIDs killed, throwaway files deleted, git status identical, 0 secret patterns in laravel.log, only DB15 keys touched.

Operational notes: the audit consumer is manual (`php artisan moderation:audit consume`), like StoryLens — Gate 10 runbook wires it.
Cutover order: maintenance mode → migrate → `moderation:import-sql` → `moderation:rebuild-queue` → up.
