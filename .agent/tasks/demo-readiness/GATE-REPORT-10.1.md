# Gate 10.1 implementation report

Branch `demo/readiness-wiring`, base `5ffcd586acc0305000affbde3276b5b1f5d36acb`. Implementation and verification stop before commit and merge.

## Decisions

- **Scheduler.** StoryLens timeline, StoryLens graph and moderation audit each process up to 100 deliveries every 10 seconds, with separate five-minute overlap locks and background execution. Moderation repair runs every five minutes with a 30-minute overlap lock and the default 600-second claim lease. Review of the repair path showed idempotent report registration, claim recovery, settlement, visibility updates and queue reconstruction without canonical deletions or operator flags.
- **Fixture references.** Resolve exact active Source feed URLs and Category slugs independently. The two frozen feed URLs and `the-gioi` slug match the canonical records. Preserve populated canonical IDs and both frozen snapshots. Re-ingesting the same fixture fills missing IDs in previously attached articles without creating Story events or changing their versions. The article route still requires both referenced records to be active.
- **Anonymized comments.** Check `account_deleted` at the service entry point and at the Blade menu. Report attempts return `Không thể báo cáo bình luận của tài khoản đã xóa.` without creating a report.
- **Synthetic workload.** Manifest v1 pins seed `10410`, 16 readers, 48 events and a UTC window spanning October and November 2026. It selects up to ten articles by original URL then Mongo ID and pins that list per seed. Accounts and event UUIDv7 identities are deterministic for the same seed and article IDs. Passwords are randomly generated and unpublished. The command refuses production before store access. Inspections and dry-runs are read-only.
- **Projection and retry.** The command uses one add-only service call for each bookmark and boost and the normal Reading Pass reservation, quota and grant path for unlocks. It publishes over the existing Redis streams. A per-seed cache lock serializes seeding and a MongoDB checkpoint records each successful publish. A crash between publish and checkpoint can redeliver the same event ID. Cassandra primary keys and Redis membership versions keep one logical result.
- **Labels and cleanup.** Synthetic MongoDB documents and stream payloads carry `synthetic: true`. Existing Cassandra schemas identify the workload by the synthetic reader ID and by `origin = 'synthetic'` for unlocks. Public engagement counts, aggregate dashboard totals and live trending exclude the workload. The regular trending logic uses a separate sorted set for each seed and account purge retracts scores from that key. Synthetic pending unlocks use wall-clock reservation age for cleanup and the manifest time for history. The runbook documents both retirement through account deletion and full removal from the active demo by switching dedicated stores.

## File inventory

| Outcome | Files |
|---|---|
| Scheduling | `routes/console.php`, `tests/Feature/DemoScheduleTest.php` |
| Fixture links | `app/Content/ContentCatalog.php`, `tests/Feature/StoryCatalogTest.php` |
| Report guard | `app/Moderation/ModerationService.php`, `resources/views/components/comment-kebab.blade.php`, `tests/Feature/ModerationUserReportTest.php` |
| Workload CLI and manifest | `app/Console/Commands/DemoSynthetic.php`, `app/Demo/SyntheticWorkload.php`, `tests/Fixtures/synthetic-workload-v1.json`, `tests/Feature/SyntheticWorkloadTest.php` |
| Interaction projection and count isolation | `app/Interaction/InteractionToggle.php`, `app/Interaction/InteractionEvent.php`, `app/Interaction/Trending.php`, `app/Interaction/MongoInteractionReplaySource.php`, `app/Models/InteractionMembership.php`, `app/Support/MongoCounts.php`, `app/Http/Controllers/Admin/DashboardController.php`, `app/Accounts/AccountPurge.php` |
| Reading Pass projection and reservation age | `app/Services/ReadingPassService.php`, `app/ReadingPass/UnlockLedger.php`, `app/ReadingPass/ReadingPassHistory.php`, `app/Console/Commands/CleanupOldGuestUnlocks.php`, `tests/Feature/ReadingPassNosqlTest.php` |
| Documentation | `docs/database/nosql-local.md`, `.agent/tasks/demo-readiness/GATE-REPORT-10.1.md` |

## Test changes

Two new test methods. `DemoScheduleTest` checks the concrete scheduler entries, cadences, locks and background flags. `SyntheticWorkloadTest` exercises the CLI through real isolated stores, including production refusal, read-only preview, retry after a publish failure, repeat seeding before and after consumption, and two clean-store runs over identical article IDs. Both runs verify identical logical users, events, top five and Cassandra partition counts. Each run verifies 32 user activity rows, 32 article activity rows and 16 user unlock rows.

| Existing test | Reason for edit |
|---|---|
| `StoryCatalogTest::test_article_to_story_interruption_recovers_identity_and_exactly_one_attachment_event` | Verify that snapshot-only ingest still works when both canonical records are missing. |
| `StoryCatalogTest::test_frozen_evidence_is_available_before_projection_and_reingest_does_not_reattach` | Check the supported article URL, the inactive-source fallback, and repair by re-ingestion without replacing article IDs or adding Story events. The name now reflects the distinction between reference repair and attachment. |
| `ModerationUserReportTest::test_hidden_deleted_and_anonymized_comments_cannot_be_reported` | Verify that an eligible reporter sees the anonymized comment without its report control and a direct POST returns the Vietnamese reason with zero reports created. |
| `ReadingPassNosqlTest::test_cleanup_deletes_old_guest_unlocks_and_abandoned_reservations_only` | Verify that a fresh synthetic reservation with a historical event time survives the cleanup job and an abandoned synthetic reservation is removed. |

## Verification

Logs are in `/tmp/vietfeed-gate-10.1/`. Each log records the command's exit status before output is read. Suites ran sequentially using MongoDB and Cassandra `vietfeed_test`, guarded Redis DB15 and test-scoped Neo4j data.

| Command | Exit | Result |
|---|---:|---|
| `php -l <file>` on each changed PHP and Blade file | 0 | 23 files, no syntax errors |
| `vendor/bin/pint <changed-files>` | 0 | Passed on the final file set |
| `vendor/bin/pint --test <changed-files>` | 0 | Passed on the final file set |
| `php artisan test tests/Feature/DemoScheduleTest.php tests/Feature/SyntheticWorkloadTest.php tests/Feature/StoryCatalogTest.php tests/Feature/ModerationUserReportTest.php tests/Feature/ReadingPassNosqlTest.php` | 0 | 39/39 tests, 1,989 assertions |
| `composer run test` | 0 | 593/593 tests, 13,127 assertions |
| `php artisan test tests/Integration` | 0 | 36/36 tests, 828 assertions |
| `git diff --check` | 0 | No whitespace errors |
| `git diff --no-index --check -- /dev/null <new-file>` for each of the six new files | 0 for the aggregate check | No whitespace diagnostics |

The initial four-file focused run also passed (`targeted-first.log`, exit 0, 22/22 tests). Pint was scoped to changed files, preserving the eight pre-existing repo-wide findings. No development data, owner work, dependencies or migrations were changed.

## Follow-ups

- Confirm scheduler convergence with the timed live-demo rehearsal in Gate 10.4. The tests verify registration and the consumer commands in isolated stores.
- The existing Integration fixture printed `-0.360 seconds` using wall-clock timing. All convergence assertions passed. Use a monotonic clock when collecting rehearsal durations.
- Cassandra activity tables lack a dedicated synthetic column. Account purge retains anonymized per-article activity, so keep the workload checkpoint for its event IDs or switch to fresh dedicated stores when the rehearsal needs a clean history.
- Later slices cover external-service fallbacks and Stripe smoke (10.2), the larger frozen dataset and human claim review (10.3), and reset automation and rehearsal (10.4).

## Fix round 1

| Finding | Resolution |
|---|---|
| F1 — Consumer runtime | `ProjectionConsumeBudget` checks a 240-second monotonic deadline before taking each delivery. The StoryLens and moderation audit consume actions arm a 270-second CLI alarm before entering their loops. The default SIGALRM action terminates a worker blocked on a socket before the 300-second overlap lock can expire. A normal return or exception restores the previous handler and remaining alarm. Scheduled consumers refuse to run without PCNTL. Manual consumers retain the between-deliveries budget on that configuration. |
| F2 — Frozen corpus | The workload manifest names `storylens-rss-v1.json`. Seed, preview and inspect require all ten fixture URLs with their expected StoryLens keys and an assigned Story. An incomplete fixture reports the ingest command before creating readers or a checkpoint. URL order determines selection and breaks score ties in inspect. Each seed keeps its per-store article-ID pin, and an incompatible pin requires a new seed. Fresh imports reproduce the logical events and ranking by URL with their own MongoDB article and event IDs. |
| F3 — Removal | The NoSQL guide recommends dedicated throwaway stores and documents immediate removal from the active demo by switching stores. In shared demos the existing account lifecycle has a 30-day default recovery window before purge and retains anonymized per-article history. This round uses that existing removal path. |
| F4 — Admin article counts | The article detail excludes synthetic unlocks from its granted unlock count, matching the bookmark and boost counts. |
| F5 — Lock durations | `DemoScheduleTest` checks the 5-minute consumer locks and the 30-minute repair lock, as well as alarm installation and restoration. |

Existing tests only. The synthetic workload test now ingests into each clean
store with different live RSS rows, rejects a partial fixture with an
RSS-only copy of the missing URL, then compares logical events, top five and
Cassandra counts by URL. Its per-store rerun checks still compare raw events.
The existing admin counts test adds synthetic rows for all three engagement
types. The moderation audit test and StoryLens integration fixture use
their CLI consume actions for the first pass.

### Verification

Logs are in `/tmp/vietfeed-gate-10.1-fix-1/`. Store-backed suites ran
sequentially in the existing isolated test stores.

| Command | Exit | Result |
|---|---:|---|
| `php -l <file>` on each changed Gate 10.1 PHP and Blade file | 0 | 30 files, no syntax errors |
| `vendor/bin/pint <changed-files>` | 0 | Passed |
| `vendor/bin/pint --test <changed-files>` | 0 | Passed |
| `php artisan test tests/Feature/DemoScheduleTest.php tests/Feature/SyntheticWorkloadTest.php tests/Feature/CatalogArticlePagesTest.php tests/Feature/ModerationNosqlTest.php` | 0 | 39/39 tests, 1,860 assertions |
| `php artisan test tests/Integration/StoryProjectionsTest.php --filter=test_full_fixture_converges_and_mongo_rebuilds_lost_dispatch_and_projections` | 0 | 1/1 test, 51 assertions |
| `composer run test` | 0 | 593/593 tests, 13,152 assertions |
| `php artisan test tests/Integration` | 0 | 36/36 tests, 830 assertions |
| `git diff --check` | 0 | No whitespace errors |
| Whitespace check of new round files and the untracked report | 0 for the aggregate check | No whitespace diagnostics |
| Standalone CLI alarm smoke with a blocked local socket pair and a one-second test alarm | 142 | Expected SIGALRM termination |
| Standalone CLI budget and missing-PCNTL smoke | 0 | Budget stops before the next delivery, scheduled mode refuses before invoking the callback |

## Fix round 2

- StoryLens and moderation audit use the existing reserved `--consumer=scheduler` name to opt into the 270-second fatal CLI alarm. Other consumer names use the 240-second between-deliveries budget and leave caller timers and signal handlers alone.
- The scheduled helper cancels the prior timer to read its remaining time and arms the earlier deadline. When the prior alarm wins, its handler stays in place and its timer continues through the return. An alarm that fired is left expired. If PHP queued an expired alarm for synchronous dispatch before entry, the helper dispatches it under the existing handler before arming its own timer. When the scheduler deadline wins, the helper uses the OS default action and restores the caller's handler and any time still remaining on return or exception.
- The existing `DemoScheduleTest` checks the reserved consumer name, manual mode, both alarm orderings, and delivery of the earlier signal during consumption and when already queued on entry. The scheduler guide documents the reserved name and alarm precedence.

### Verification

Logs are in `/tmp/vietfeed-gate-10.1-fix-2/`. Store-backed suites ran sequentially against the existing isolated test stores.

| Command | Exit | Result |
|---|---:|---|
| `php -l` on both round 2 PHP files | 0 | No syntax errors |
| `vendor/bin/pint app/Console/ProjectionConsumeBudget.php tests/Feature/DemoScheduleTest.php` | 0 | Passed |
| `vendor/bin/pint --test app/Console/ProjectionConsumeBudget.php tests/Feature/DemoScheduleTest.php` | 0 | Passed |
| `php artisan test tests/Feature/DemoScheduleTest.php` | 0 | 1/1 test, 58 assertions |
| `composer run test` | 0 | 593/593 tests, 13,170 assertions |
| `php artisan test tests/Integration` | 0 | 36/36 tests, 830 assertions |
| `git diff --check` | 0 | No whitespace errors |
| Standalone CLI alarm smoke with a blocked local socket pair and a one-second test alarm | 142 | Expected SIGALRM termination |

## Fix round 3

- Account purge captures the user document's `synthetic` flag in its claim context. A resumed claim that predates the flag fills it from the user document before running the remaining steps. Retained Cassandra article-day rows use `deleted-synthetic` for synthetic readers and `deleted` for real users. The existing `user_id` column carries the marker. The change applies when the anonymization step runs.
- The default admin user list and its pagination total exclude synthetic readers, matching the dashboard. The **Tài khoản mô phỏng** filter at `/admin/users?synthetic=1` lists only synthetic readers and stays selected when searching or paging. Retirement uses the existing account deletion lifecycle.
- Extended the existing account lifecycle test to purge a real account and a synthetic reader with an older claim context. It verifies both article-day markers and removal of the synthetic reader's user-month activity. Extended the existing Pro/admin list test to verify the default dashboard/list totals and filtered search. The NoSQL guide documents the markers and the retirement filter.

### Verification

Logs are in `/tmp/vietfeed-gate-10.1-fix-3/`. Store-backed suites ran sequentially against the existing isolated test stores.

| Command | Exit | Result |
|---|---:|---|
| `php -l` on the five round 3 PHP and Blade files | 0 | No syntax errors |
| `vendor/bin/pint <changed-files>` | 0 | Passed |
| `vendor/bin/pint --test <changed-files>` | 0 | Passed |
| `php artisan test tests/Feature/AccountLifecycleTest.php tests/Feature/ProSubscriptionTest.php` | 0 | 20/20 tests, 458 assertions |
| `composer run test` | 0 | 593/593 tests, 13,184 assertions |
| `php artisan test tests/Integration` | 0 | 36/36 tests, 830 assertions |
| `git diff --check` | 0 | No whitespace errors |

## Control final gate (2026-11-24)

Reviews: REVIEW-1 Opus 5.5 via agy (APPROVE WITH FIXES) → Sol fix round 1 → REVIEW-2 interim GPT 6.1 Terra (Opus quota out) →
Sol fix round 2 → REVIEW-3 Opus 5.5 APPROVE + REVIEW-4 Gemini 4 Hydrogen data-integrity (1 MAJOR, 1 MINOR) → Sol fix round 3 →
REVIEW-5 (Opus "REVIEW-4") APPROVE.

| Command | Result |
| --- | --- |
| `composer run test` | exit 0, 593/593 (13 184 assertions) |
| `php artisan test tests/Integration` | exit 0, 36/36 |
| `vendor/bin/pint --test` on changed/new PHP files | exit 0 |
| `git diff --check` | exit 0 |

No browser acceptance for this slice (backend wiring, CLI and admin filters; covered by feature tests). Live scheduler timing is
rehearsed in Gate 10.4. Owner note from REVIEW-3: `SourceSeeder` (owner WIP) is keyed on `feed_url`; re-seeding with the changed
Tuổi Trẻ URLs leaves the old sources active beside new ones.
