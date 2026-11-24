<!-- Read-only review by Claude Opus 5.5 via agy (plan mode), 2026-11-24; Claude Code Opus quota exhausted until 2026-11-28. -->
# REVIEW-1 — Gate 10.1

Reviewer: Claude Opus 5.5 (read-only, file reads only; `.env` not opened). Branch `demo/readiness-wiring`, uncommitted tree.

## Verdict: **APPROVE WITH FIXES**

There is one MAJOR (scheduler runtime bound) and three MINOR findings. All five outcomes are otherwise met, and the test budget is respected.

---

## Findings

### F1 — MAJOR — New 10 s consumers have no runtime bound below their 5-minute overlap lock
- **Where:** `routes/console.php:18-20`; `app/Console/Commands/StoryLens.php:52-53`; `app/Console/Commands/ModerationAuditCommand.php:34-35`.
- **Problem:** The repo has a rule that a consumer's runtime must be shorter than its mutex. See `routes/console.php:15` ("The consumer's alarm is shorter than this mutex") and `docs/database/nosql-local.md:1386-1390`, where sources history uses a 35 s budget, a 45 s PCNTL alarm and a 1-minute mutex. `rbac:audit retry` follows the same rule (`nosql-local.md:1786-1788`). The three new entries don't. `storylens consume` and `moderation:audit consume` have no budget or alarm, yet they hold `withoutOverlapping(5)` + `runInBackground`. The docs themselves say the CQL driver can wait forever on a peer that accepts a connection and never answers (`nosql-local.md:1442-1443`).
- **Scenario:** During the failure demo, `docker pause` is run on Neo4j or Cassandra, or a peer hangs.
  1. The graph or timeline consumer blocks inside a socket read.
  2. After 5 minutes the mutex expires and the scheduler starts another `--consumer=scheduler` process. That one blocks too.
  3. This repeats: one more hung PHP process every 5 minutes on the 8 GB demo host.
  4. When the store comes back, two or more workers run the same section at once. Concurrent same-section workers are outside the tracer's contract (`nosql-local.md:387-389`, `:623`).
- **Fix:** Copy the `sources:history-manage` pattern into both consume actions:
  - check a monotonic budget (for example 240 s) before taking each delivery;
  - arm `pcntl_alarm` (for example 270 s) when PCNTL is available, so the alarm fires before the 300 s mutex expires.

  Document this in the scheduler table. If it is out of scope, use `withoutOverlapping(60)` instead and record the residual risk in the gate report.

### F2 — MINOR — Synthetic article selection includes live RSS, so the workload isn't reproducible across resets
- **Where:** `app/Demo/SyntheticWorkload.php:139-142` (`Article::orderBy('original_url')->orderBy('_id')->limit(10)` over **all** articles); `docs/database/nosql-local.md:1997-2000`.
- **Scenario:** On a demo machine where `feeds:fetch` runs every 30 minutes, the first `seed` pins whatever ten URLs sort first at that moment. After the documented safe reset (fresh stores, `db:seed`, `storylens ingest`, `feeds:fetch`), the corpus is different, so the same seed picks different articles, a different top-K and different event IDs. Gate 10 says "deterministic synthetic workload is versioned", but this only holds inside one store, and only after pinning.
- **Fix:** Pick from the frozen corpus only, for example `whereNotNull('story_key')` (the fixture articles), and refuse to run when there are fewer than `article_limit`. Another option is to list the fixture `original_url`s in `synthetic-workload-v1.json`. Keep the per-seed pin as it is.

### F3 — MINOR — The "retire a seed" path takes 30 days and 16 manual admin actions
- **Where:** `docs/database/nosql-local.md:2040-2046`. Purge runs only after `purge_after` (`AccountPurge.php:68-74`), and `ACCOUNT_RECOVERY_DAYS` defaults to 30 (`nosql-local.md:1847-1848`).
- **Scenario:** An operator follows "request deletion … and allow the account purge to run". The synthetic readers stay visible in the admin user list, and their ranking key stays in place, for 30 days.
- **Fix (docs only):** Say plainly that retirement waits for the recovery window. Recommend the dedicated-store switch as the normal removal path for a demo. Alternatively, document setting `ACCOUNT_RECOVERY_DAYS` for disposable demo stores.

### F4 — MINOR — The admin article page mixes filtered and unfiltered counts
- **Where:** `app/Http/Controllers/Admin/ArticleController.php:94-95`.
- **Scenario:** `ArticleCounts::attach` leaves synthetic bookmarks and boosts out (`MongoCounts.php:31-33`), but the next line, `unlocks_count`, counts every granted unlock. On the same page, a seeded article shows 0 boosts but N unlocks. The dashboard was fixed (`DashboardController.php:36-37`); this page was missed.
- **Fix:** Add `->where('synthetic', '!=', true)` to line 95.

### F5 — NIT — The schedule test doesn't check lock TTLs
- **Where:** `tests/Feature/DemoScheduleTest.php:19-23`.
- **Detail:** The test asserts `withoutOverlapping`, `runInBackground`, the cadence and the cron expression, but not `expiresAt` (5 and 30 minutes). Those values are what F1 depends on.
- **Fix:** Add `assertSame($command === 'moderation:repair' ? 30 : 5, $event->expiresAt)` inside the existing loop. This needs no new test method.

---

## Checked areas with no defect found

| Area | Evidence |
|---|---|
| `moderation:repair` every 5 min with the 600 s lease | `ModerationRepair.php:13-27` takes only `--lease` (minimum 60 s), with no `--force` or delete. `ModerationService::repair` (`:298-319`) skips claims younger than the lease (`:328-331`). Its steps are conditional or idempotent: register, confirm or release, settle, conditional hide/unhide, orphan flag. Queue rebuild uses a lock, an atomic swap and a post-swap resync (`ModerationQueue.php:170-231`). Evidence is never deleted. Cost per run is O(reports + sanctions) scans plus one rebuild, which is fine at demo scale. |
| Consumer names | The scheduler uses `scheduler`. Manual defaults are `local` (`StoryLens.php:19`, `ModerationAuditCommand.php:13`) and the docs use `timeline-local`, `graph-local` and `local`, so names don't collide. The docs say to pause the scheduler before `reemit` or failure tests (`nosql-local.md:58-60`). |
| Ingest ID resolution | Exact match on active `feed_url` and `slug`, both backed by unique indexes (`nosql-local.md:937-938`). Only null fields are filled (`ContentCatalog.php:134-140`), and the update is conditional `whereNull` (`:67-69`). The already-attached branch returns `null`, so there is no event, no version bump and no brief queued (`:66-72`). No match means `array_filter` leaves the old behaviour unchanged. Snapshots and UUIDv5 identities are untouched. |
| Report refusal | There is one entry point, `POST /comments/{comment}/report` (`web.php:64`). It goes through `ReportController:21-24` to `ModerationService::reportComment`, whose `account_deleted` guard runs first (`:45-47`). There is no API route. The UI hides both the menu item and the modal (`comment-kebab.blade.php:4,53`). The test covers the UI, the POST and zero reports created. |
| Production refusal | The command checks three sources of the environment before any store access (`DemoSynthetic.php:21-25`). `assertSafeTestStores` does nothing outside `testing` (`StoryPipeline.php:187-191`). |
| Isolation from real users | Readers are keyed by `synthetic+v1-…@demo.invalid` and the identity check throws on any mismatch (`SyntheticWorkload.php:118-125`). Memberships and unlocks are keyed by the synthetic user, and a purged or pending reader is refused. Public counts, home, dashboard and the live trending rebuild exclude synthetic rows (`MongoCounts.php:31`, `MongoInteractionReplaySource.php:12`, `Trending.php:72`). Purge retracts boosts from the per-seed key (`AccountPurge.php:271-274`). Reading Pass quotas are per subject, so real users are unaffected. Synthetic reservations expire on wall-clock time (`ReadingPassService.php:130-136`, `CleanupOldGuestUnlocks.php:28-34`). |
| Determinism and idempotency | Seeded PCG engine. The draw order is independent of the skip-if-published check (`:87-96`). UUIDv7 IDs are deterministic (`:130-137`), there is a per-seed lock, and the checkpoint uses `$addToSet`. The redelivery window is documented. |
| External calls | No Stripe, mail or network calls. `firstOrCreate` fires no `Registered` event, and passwords are random and hashed, with resets blocked (`:119-120`). |
| Test budget | 2 new methods (`DemoScheduleTest`, `SyntheticWorkloadTest`) and 3 existing tests extended. That is within the limit of 4. |
