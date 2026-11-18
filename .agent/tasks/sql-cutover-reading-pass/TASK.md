# Gate 9.5 slice 3b — Reading Pass (ArticleUnlock) on Redis counters + durable history

Canonical sources: `docs/planning/technical-gates.md` Gate 9.5, `docs/specs/vietfeed-nosql-storylens.md` §7 story 15 ("Reading Pass
usage and history correct and recoverable"), §9.1–9.4, §13, `docs/architecture/database-feature-map.md` (Reading Pass: plan in
MongoDB subscription, Redis atomic quota counter, Cassandra unlock history queried by user/month; Redis must never be the only durable
holder of state and must be rebuildable after a flush).

One slice, one branch, one PR: branch `cutover/reading-pass`, base `main` `ea30e3a`.

## Recon facts (control, 2026-11-18)

- `App\Services\ReadingPassService` (SQL `ArticleUnlock`): tiers — admin unlimited (no record), Pro unlimited (recorded), verified user
  15 per rolling 5 hours (with "next unlock" label), unverified account 8 per Vietnam day, guest 5 per Vietnam day per session;
  "already unlocked this article" grants access without consuming; `accessOrLock` is check-then-insert (NOT atomic: concurrent requests
  can exceed the quota); `transferGuestUnlocks` on login/register/Google moves a session's guest unlocks to the user, dropping
  duplicates; `unlocks:cleanup` deletes guest unlocks older than 30 days (scheduled daily).
- Callers: `ArticleController::show`, `ProfileController::readingPass`, auth controllers (login, register, Google callback),
  `AppLayout` navbar allowance, `Admin\UserController` (allowance per listed user), `Admin\DashboardController` (unlocks today / last
  5 hours), `DemoEngagementSeeder`, tests (`ReadingPassTest`, `ProSubscriptionTest`, catalog page query-count tests).
- Infrastructure to reuse: `nosql` Redis connection + `App\Interaction\RedisResult` + Lua patterns (moderation queue, trending),
  `CassandraClient` + idempotent insert/DLQ pattern (`ModerationAudit`, `InteractionActivity`), stream consumers. Redis is allkeys-lru
  (keys can vanish); MongoDB standalone (no transactions).

## Required outcomes

1. **Durable record** of unlocks that answers "already unlocked" and rebuilds counters: choose MongoDB current-state collection
   (`article_unlocks`, unique {subject, article}) and/or Cassandra history (`unlocks_by_user_month`, `unlocks_by_session_day`), justify
   against the feature map; Cassandra history is required for "history by user/month" (profile history view if one exists, admin/demo
   query otherwise).
2. **Atomic quota** in Redis: one Lua script decides and consumes (already-unlocked check, window count, limit) so concurrent requests
   never exceed the limit; rolling 5-hour window for verified users (e.g. sorted set of unlock timestamps), Vietnam-day windows for
   accounts/guests; TTLs bound key lifetime.
3. **Eviction/flush safety**: when quota keys are missing, they are rebuilt from the durable record before deciding (under a lock, or
   via a sentinel like the moderation queue), so a flush never grants extra unlocks beyond the limit nor denies paid/allowed ones.
   Redis down → documented behaviour (choose: fail-open with durable record, or fail-closed with a friendly message) and tested.
4. **Behaviour parity**: every tier, label ("next unlock", reset), admin no-record, Pro recorded, guest transfer on all three auth paths
   (dedupe), cleanup of old guest data (Redis TTL + durable cleanup), navbar and profile allowance, admin counts — unchanged for users.
5. **Data move**: idempotent `unlocks:import-sql` from SQL `article_unlocks` (natural key subject+article, `legacy_sql_id`), rebuild of
   counters; runs once (refuse rerun without `--force`, like slice 3a); SQL table left unused. Dev stays "fresh".
6. **Tests** on isolated stores (Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`, SQLite `:memory:`): all tiers and
   windows (deterministic clock), already-unlocked, concurrent unlock attempts at the limit (Lua path), key eviction → rebuild →
   same decision, Redis down behaviour, guest transfer, cleanup, import idempotency, grep proof that only the import reads SQL
   `article_unlocks`. Existing tests green with storage-only edits (listed with reasons).
7. **Docs**: `docs/database/nosql-local.md` (keys, Lua, tables, inspection), `GATE-REPORT-9.5-3b.md` with the decision log.

## Hard rules / isolation

Mongo `vietfeed_test`, Redis DB15 guard, Cassandra `vietfeed_test`, SQLite `:memory:`. Never touch dev data (Mongo `vietfeed`, Redis
DB0/1/2, Cassandra `vietfeed`); no FLUSHALL, no migrate/seed/new commands against dev, no container starts/restarts. Sequential suites.
Never edit existing migrations. No new dependencies. Owner WIP untouched.

## Non-goals

Changing limits/tiers/copy, Billing, SourceFetchLog (slice 4), dropping SQL tables.

## Stop conditions

Product change needed (e.g. limits); owner WIP edit; failure not fixable in scope; container restart or OOM.
