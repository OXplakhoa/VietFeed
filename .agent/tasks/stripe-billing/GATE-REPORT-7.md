# Gate 7 — Stripe-to-Mongo billing

## Implementation

### Plan

1. Preserve owner WIP hashes in `/tmp/gate7-wip.sha256`; read canonical billing contracts and existing billing/tests. Keep active .env and all owner material untouched.
2. Write failing Mongo projection/signature tests first, then implement `App\Billing` with a Stripe interface, SDK adapter, signed webhook receipt, retryable Mongo claims and atomic ordered subscription projection. Use event created timestamp, conservative status precedence for ties, event ID as final tie breaker, and terminal deletion tombstones.
3. Write failing Checkout/Portal/entitlement/admin tests; replace Cashier entry points with the module, backfill legacy customer IDs and create durable Mongo indexes via an idempotent migration. Preserve UI messages/guards and keep success redirect webhook-driven.
4. Composer dry-run first: remove Cashier and require stripe/stripe-php ^17.6, retaining every other locked package/version. Control amendment (2026-11-17): also remove unused moneyphp/money and symfony/polyfill-intl-icu; retain Stripe v17.6.0 and its exact dist reference. Stop on other package changes.
5. Update the existing SQL subscription fixtures/query-count setup; prove real concurrent duplicate processing, retry after partial failure, reversed/tied event ordering, safe error logs and absence of SQL billing usage.
6. Write the real Test Mode reviewer walkthrough and collection documentation. Run Pint on touched PHP files, each changed/new test file three consecutive times, then the full suite sequentially, platform requirements, grep, route lists, WIP checksums and final status. Record true command exits. Do not run real Stripe acceptance or any dev migrations.

### Initial evidence

- Branch `billing/stripe-mongo`, HEAD `0d2f8787f387e8fbb3c60320281a1cae3d162e67`.
- No cached `bootstrap/cache/config.php` (check exit 0).
- Five tracked owner WIP files fingerprinted before any edit; manifest outside repository.
- Tests use existing Mongo vietfeed_test / SQLite :memory: assertions and Redis DB15 live guard; no container changes or delegation.

### Implementation result

STATUS: **READY_FOR_REVIEW**. Implementation and automated verification complete;
independent review and real Stripe Test Mode acceptance remain with control.
No commits, staging, stash, branch switches, push, PR, delegation, active .env
changes, dev migrations or container operations were performed.

Control resolved the initial Composer stop on 2026-11-17: the two unused transitive
removals are allowed, Stripe must remain v17.6.0. Targeted `--minimal-changes`
resolution produced exactly the three approved removals and no retained-package
changes. Composer-generated plugin API metadata and platform-key ordering were
restored to the originals; its content hash was recomputed with the installed
Composer Locker API after restoring original composer.json formatting. Final
validation and an exact-text lock comparison pass. No dependency workaround or
additional root requirement was introduced.

### Decisions and why

1. **Module boundary:** `App\Billing\BillingGateway` owns Checkout, Portal,
   signature verification, receipt processing, projection, entitlement and customer
   dashboard URLs. `StripeClient` is the network seam; `StripeSdkClient` implements
   it with the existing SDK version. Tests bind a fake, never call Stripe. Only
   the module accesses billing collections at runtime; migrations/tests inspect
   them directly. No Redis/Cassandra/Neo4j dependency was added.
2. **Ordering:** markers compare `(event.created seconds, status rank, event ID)`
   lexically with fixed-width numeric components. Rank is trialing 10, active 20,
   incomplete 30, past_due 40, paused 50, unpaid 60, incomplete_expired 70, canceled
   80, unknown 90, deleted-event 99. Restrictive states win same-second ties; IDs
   provide deterministic convergence, not inferred chronology. The atomic Mongo
   predicate rejects equal/older markers. A projected deleted subscription is
   terminal forever; a new subscription ID can grant Pro. Tests cover reversed
   timestamps, tied restrictive states, tied IDs, tied deletion in both orders,
   and attempted resurrection even with a later active event.
3. **Idempotency/retry:** Stripe event ID is Mongo `_id`, intrinsically unique.
   Receipt insertion precedes a conditional claim with a random token and a
   60-second lease. Only completion sets `processed_at`; caught failures release
   the claim, hard crashes expire. Processed duplicates return 200. An in-flight
   duplicate returns retryable 503 rather than acknowledging unfinished work.
   Projection itself is an atomic conditional update, so expired/overlapping
   claim holders or a crash after projection cannot apply the same marker twice.
   Claim-token predicates fence receipt completion/cleanup. No replica-set or
   multi-document transaction is required. Two independent PHP HTTP workers prove
   one receipt and projection_version=1, with safe redelivery after the race.
4. **Entitlement:** `User::isPro()` is true iff at least one Mongo document for
   the user's UUID has `type=pro`, status `active` or `trialing`. Neither scheduled
   cancellation nor a timestamp independently removes Pro while that status stays
   active. Reading Pass and all existing badges/profile/nav/admin views retain
   the method. Admin page users get one batched distinct query, then request-local
   entitlement relations. A Mongo command observer verifies one 20-user batch
   and bounded total billing reads. Dashboard counts distinct existing Users,
   avoiding double-counting users with multiple subscriptions or orphan IDs.
5. **Customer identity/backfill:** Checkout creates a Customer with user_id metadata
   using a stable per-user Stripe idempotency key and conditionally persists
   `stripe_customer_id`; later Checkouts reuse it. The migration backfills only
   missing/null new IDs from nonempty legacy Mongo `stripe_id`; it preserves an
   existing new ID and **does not delete the old field**. Sparse unique customer,
   unique subscription and user lookup indexes are durable. Event uniqueness is
   Mongo's native `_id` index, created with the receipt collection. Tests rerun
   the migration twice and prove duplicate identity rejection on vietfeed_test.
6. **Webhook contract:** stateless POST /stripe/webhook excludes web middleware
   and is explicitly CSRF exempt. SDK validation uses the raw body and default
   300-second tolerance. Missing/invalid/expired signatures and malformed JSON or
   event envelopes return 400 before writes. Four handled types project state;
   Checkout completion fetches the subscription through the interface rather
   than trusting Session payment fields. Resolve by subscription metadata user_id,
   then new customer ID; unknown users and ignored types return 200 with a receipt.
   Success URL remains webhook-driven and never trusts its query parameters.
7. **Failure/privacy:** Checkout/Portal show Vietnamese retry flashes on Stripe
   failure. Webhook processing failures return generic 503. Logs contain operation
   and exception class, or an unknown-user event ID; no original exception message,
   trace, signature, secret or payload is logged. SensitiveParameter protects raw
   webhook arguments. Read-only entitlement pages continue from Mongo. Returning
   a direct Symfony Response avoids Laravel's response factory constructing a
   session manager; a test disables SQL and selects a database session driver
   while webhook processing/entitlement remain functional.
8. **Removed:** Cashier trait/dependency/config, five Cashier SQL migrations,
   obsolete customer/payment/trial fillable/cast fields and example currency vars;
   SQL subscription fixtures, admin whereHas calls and catalog relationship warmup.
   The legacy Stripe field remains only for backfill and its tests. Non-billing
   SQL remains in scope as before. Admin's cross-store withCount was replaced by
   three grouped SQL counter reads for the page because Mongo Users cannot join
   those legacy tables; counters and page behavior are preserved.

### Files changed by this worker

- `.agent/tasks/stripe-billing/GATE-REPORT-7.md`
- `.env.example`
- `app/Billing/BillingGateway.php`
- `app/Billing/BillingUnavailable.php`
- `app/Billing/InvalidWebhook.php`
- `app/Billing/StripeClient.php`
- `app/Billing/StripeSdkClient.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Controllers/BillingController.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `composer.json`
- `composer.lock`
- `config/cashier.php`
- `config/services.php`
- `database/migrations/2026_06_03_233155_create_customer_columns.php`
- `database/migrations/2026_06_03_233156_create_subscriptions_table.php`
- `database/migrations/2026_06_03_233157_create_subscription_items_table.php`
- `database/migrations/2026_06_03_233158_add_meter_id_to_subscription_items_table.php`
- `database/migrations/2026_06_03_233159_add_meter_event_name_to_subscription_items_table.php`
- `database/migrations/2026_11_17_000001_create_mongodb_billing_indexes.php`
- `docs/database/nosql-local.md`
- `docs/demo/billing-demo.md`
- `resources/views/layouts/app.blade.php`
- `routes/web.php`
- `tests/Doubles/FakeStripeClient.php`
- `tests/Feature/CatalogArticlePagesTest.php`
- `tests/Feature/ProSubscriptionTest.php`
- `tests/Feature/RedisStreamsTest.php`
- `tests/Feature/StripeBillingTest.php`
- `tests/TestCase.php`

### Verification commands and real exit codes

All captured commands used direct `> /tmp/gate7-*.log 2>&1` followed by
`echo exit=$?`, or a directly returned command exit. No piped test output.
The triple-run loop captured `$?` immediately for each invocation and stopped on
failure. Suites ran sequentially; only the explicit duplicate-race test used
concurrent workers. Every test application asserts Mongo vietfeed_test and SQLite
:memory: before migrations. Existing Redis DB15/getDbNum guards are unchanged.
No cached bootstrap/cache/config.php existed before tests.

| Command | Exit(s) | Result |
|---|---:|---|
| Initial `composer update laravel/cashier stripe/stripe-php --dry-run --no-scripts` | 0 | Original stop, resolved by control amendment. No update applied then. |
| `composer update laravel/cashier --minimal-changes --dry-run --no-scripts` | 0 | Exactly three approved removals, zero upgrades/installs. |
| `composer update laravel/cashier --minimal-changes --no-scripts` | 0 | Applied approved removals; Stripe retained. |
| `composer dump-autoload --no-scripts` | 0 | Regenerated autoloader after new module. |
| `php artisan package:discover` initial / after regenerating ignored packages/services caches | 1 / 0 | Initial stale Cashier provider cache resolved; no config cache involved. |
| Initial `vendor/bin/phpunit tests/Feature/StripeBillingTest.php` | 255 | Expected red: interface absent. |
| Projection test after code / after cache regeneration | 2 / 0 | Stale provider cache, then 3 tests / 33 assertions green. |
| Checkout/admin red / after admin fix (`ProSubscriptionTest.php`) | 1 / 0 | Removed relation still queried by admin; then 7 / 94 green. |
| Expanded retry/isolation tests red / after fixes (`StripeBillingTest.php`) | 2 / 0 | Interruption seam, implicit _id uniqueness representation and response-factory session dependency resolved; 12 / 136 green. |
| `vendor/bin/phpunit --no-extensions --filter test_webhook_and_entitlement tests/Feature/StripeBillingTest.php` | 2 | Diagnosed implicit session construction; fixed. |
| `vendor/bin/phpunit --filter test_webhook_and_entitlement tests/Feature/StripeBillingTest.php` diagnostic | 2 | Same expected red; temporary trace output removed before final tree. |
| Concurrent/privacy expansion `vendor/bin/phpunit tests/Feature/StripeBillingTest.php` | 0 | 14 / 169, before final scheduled-cancel case. |
| Admin batch expansion `vendor/bin/phpunit tests/Feature/ProSubscriptionTest.php` | 0 | 8 / 103. |
| `test ! -e bootstrap/cache/config.php` | 0 | No cached config before mutating suites. |
| `vendor/bin/phpunit tests/Feature/StripeBillingTest.php` ×3 | 0 / 0 / 0 | **15 tests / 182 assertions each**. |
| `vendor/bin/phpunit tests/Feature/ProSubscriptionTest.php` ×3 | 0 / 0 / 0 | **8 / 103 each**. |
| `vendor/bin/phpunit tests/Feature/RedisStreamsTest.php` ×3 | 0 / 0 / 0 | **29 / 495 each**. |
| `vendor/bin/phpunit tests/Feature/CatalogArticlePagesTest.php` ×3 | 0 / 0 / 0 | **16 / 308 each**. |
| `composer run test` | 0 | **232 tests / 3741 assertions**, up from supplied 212 / 3475. No test notices/warnings reported. |
| `composer check-platform-reqs` | 0 | PHP 8.4.27, MongoDB 2.5.5, Redis 6.3.0; all requirements success. |
| `composer validate --no-check-publish` initial / final | 2 / 0 | Content-hash formatting mismatch corrected using installed Composer Locker::getContentHash; final valid. |
| `python3 /tmp/gate7-verify-lock.py` | 0 | Exact original text minus three approved package blocks and required content-hash; retained Stripe record unchanged. |
| `composer show stripe/stripe-php` (captured, then `head -5` of log) | 0 | v17.6.0; output below. |
| `git diff --stat composer.json composer.lock` | 0 | Output below. |
| `vendor/bin/pint <touched PHP paths below>` | 0 | Applied formatting. |
| `vendor/bin/pint --test <same paths>` | 0 | Passed on final PHP tree. |
| Required `rg` absence scan (exact command below) | 1 | No matches; rg's expected no-match exit. |
| `php artisan route:list --path=stripe` | 0 | One route; output below. |
| `php artisan route:list --path=billing` | 0 | Three routes; output below. |
| `sha256sum -c /tmp/gate7-wip.sha256` | 0 | All five owner WIP files unchanged; final output below. |
| `git diff --check` | 0 | No whitespace errors. |
| `git status --short` | 0 | Final full status below, including untouched owner WIP. |

The modified TestCase and new FakeStripeClient are support files without standalone
test methods; they are exercised by the repeated classes/full suite above.

Pint path arguments (deleted PHP files excluded; Blade was supplied and excluded
by Pint's normal finder):

```text
app/Billing
app/Http/Controllers/BillingController.php
app/Http/Controllers/Admin/UserController.php
app/Http/Controllers/Admin/DashboardController.php
app/Models/User.php
app/Providers/AppServiceProvider.php
bootstrap/app.php
config/services.php
database/migrations/2026_11_17_000001_create_mongodb_billing_indexes.php
resources/views/layouts/app.blade.php
routes/web.php
tests/Feature/StripeBillingTest.php
tests/Feature/ProSubscriptionTest.php
tests/Feature/RedisStreamsTest.php
tests/Feature/CatalogArticlePagesTest.php
tests/TestCase.php
tests/Doubles/FakeStripeClient.php
```

Absence scan (no output, exit 1):

```bash
rg -n -e 'Laravel\\Cashier' -e '\bBillable\b' -e "DB::table\(['\"]subscriptions['\"]\)" -e 'subscription_items' -e "whereHas\(['\"]subscriptions['\"]" app/ routes/ config/ resources/ tests/ database/
```

Dependency diff:

```text
 composer.json |   4 +-
 composer.lock | 269 +---------------------------------------------------------
 2 files changed, 3 insertions(+), 270 deletions(-)
```

`composer show stripe/stripe-php | head -5` equivalent output (Composer exit captured before head):

```text
name     : stripe/stripe-php
descrip. : Stripe PHP Library
keywords : api, payment processing, stripe
versions : * v17.6.0
released : 2025-08-27, 1 year ago
```

Locked Stripe dist reference: `a6219df5df1324a0d3f1da25fb5e4b8a3307ea16` (unchanged).

Route output:

```text

 POST stripe/webhook .. stripe.webhook › BillingController@webhook

 Showing [1] routes


 POST billing/checkout .. billing.checkout › BillingController@checkout
 GET|HEAD billing/portal .. billing.portal › BillingController@portal
 GET|HEAD billing/success .. billing.success › BillingController@success

 Showing [3] routes

```

Owner WIP verification:

```text
.agent/tasks/infra-cassandra-spike/STATUS.md: OK
.agent/tasks/mongo-foundation/STATUS.md: OK
.agent/tasks/neo4j-foundation/STATUS.md: OK
CLAUDE.md: OK
database/seeders/SourceSeeder.php: OK
```

Final `git status --short`:

```text
 M .agent/tasks/infra-cassandra-spike/STATUS.md
 M .agent/tasks/mongo-foundation/STATUS.md
 M .agent/tasks/neo4j-foundation/STATUS.md
 M .env.example
 M CLAUDE.md
 M app/Http/Controllers/Admin/DashboardController.php
 M app/Http/Controllers/Admin/UserController.php
 M app/Http/Controllers/BillingController.php
 M app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M bootstrap/app.php
 M composer.json
 M composer.lock
 D config/cashier.php
 M config/services.php
 D database/migrations/2026_06_03_233155_create_customer_columns.php
 D database/migrations/2026_06_03_233156_create_subscriptions_table.php
 D database/migrations/2026_06_03_233157_create_subscription_items_table.php
 D database/migrations/2026_06_03_233158_add_meter_id_to_subscription_items_table.php
 D database/migrations/2026_06_03_233159_add_meter_event_name_to_subscription_items_table.php
 M database/seeders/SourceSeeder.php
 M docs/database/nosql-local.md
 M resources/views/layouts/app.blade.php
 M routes/web.php
 M tests/Feature/CatalogArticlePagesTest.php
 M tests/Feature/ProSubscriptionTest.php
 M tests/Feature/RedisStreamsTest.php
 M tests/TestCase.php
?? .agent/handoff/
?? .agent/migration-pc/
?? .agent/tasks/claude-code-handoff/
?? .agent/tasks/infra-cassandra-spike/REVIEW.md
?? .agent/tasks/infra-cassandra-spike/T1-compose-health.md
?? .agent/tasks/infra-cassandra-spike/T2-cassandra-spike.md
?? .agent/tasks/infra-cassandra-spike/TASK.md
?? .agent/tasks/mongo-foundation/REVIEW.md
?? .agent/tasks/mongo-foundation/T1-mongo-package.md
?? .agent/tasks/mongo-foundation/T2-mongo-auth.md
?? .agent/tasks/mongo-foundation/T3-mongo-crud.md
?? .agent/tasks/mongo-foundation/TASK.md
?? .agent/tasks/neo4j-foundation/REVIEW.md
?? .agent/tasks/neo4j-foundation/T1-neo4j-client.md
?? .agent/tasks/neo4j-foundation/TASK.md
?? .agent/tasks/redis-cutover/CONTROL-2026-11-02.md
?? .agent/tasks/redis-cutover/CONTROL-2026-11-14.md
?? .agent/tasks/redis-cutover/IMPLEMENT-2026-11-02.md
?? .agent/tasks/redis-cutover/IMPLEMENT-2026-11-14.md
?? .agent/tasks/redis-cutover/IMPLEMENT-PROMPT-2026-11-02.md
?? .agent/tasks/redis-cutover/PR-DRAFT-14.md
?? .agent/tasks/redis-cutover/REVIEW-2026-11-02.md
?? .agent/tasks/redis-cutover/REVIEW-2026-11-14.md
?? .agent/tasks/redis-cutover/REVIEW-PROMPT-2026-11-02.md
?? .agent/tasks/redis-cutover/REVIEW.md
?? .agent/tasks/redis-cutover/T1-redis-sessions.md
?? .agent/tasks/redis-cutover/T2-redis-cache-queue.md
?? .agent/tasks/redis-cutover/T3-redis-streams.md
?? .agent/tasks/redis-cutover/TASK.md
?? .agent/tasks/stripe-billing/
?? .pi/
?? app/Billing/
?? database/migrations/2026_11_17_000001_create_mongodb_billing_indexes.php
?? docs/agents/
?? docs/demo/billing-demo.md
?? pi-warden.md
?? tests/Doubles/FakeStripeClient.php
?? tests/Feature/StripeBillingTest.php
```

### Known limitations / control acceptance

- Real Stripe acceptance was **not run**, as instructed. Control should apply the
  Mongo-only migration and execute `docs/demo/billing-demo.md`; no dev store was
  mutated by this worker. Existing legacy SQL subscriptions are not migrated;
  stale SQL data preservation is outside the canonical scope. New signed Stripe
  events populate the projection. Customer backfill is Mongo-to-Mongo only.
- Same-second nonterminal Stripe chronology cannot be reconstructed from opaque
  IDs. The documented conservative rank + lexical tie-break converges and avoids
  tied deletion resurrection, but may select a different nonterminal snapshot
  from Stripe's exact within-second final state. No reconciliation command exists.
- Receipt/projection are separate Mongo writes. A crash can leave an unfinished
  receipt or a subscription identity placeholder; Stripe retry completes it and
  atomic marker checks prevent duplicate entitlement updates. A hard crash may
  delay retry until the 60-second lease expires; application clocks should agree.
- No event retention or tombstone cleanup is provided. Unknown users are recorded
  and acknowledged, so later identity repairs require a new Stripe event or future
  reconciliation tooling. Admin Pro filtering materializes the Pro UUID set,
  appropriate for this local gate; broad scaling work is deferred.
- Per-user Customer idempotency uses Stripe's normal idempotency-key retention;
  this is not an unlimited durable external-operation guarantee. Checkout Session
  creation itself is not deduplicated across separate user submissions.
- Existing non-billing SQL stores, sanctions, Reading Pass counters, web sessions
  and the deferred account-deletion/cancellation lifecycle remain unchanged.
- Composer warned that its cache directory is unwritable (proceeded uncached) and
  reported 45 security advisories affecting 11 existing packages. No unrelated
  upgrades/audit remediation were attempted under the dependency constraint.
- Open questions for control: none blocking implementation review; real Stripe
  acceptance and the documented same-second ordering policy require reviewer sign-off.

## Fix round 1

Design (control decisions): the webhook payload is only a trigger. Each handled event retrieves the current subscription from Stripe under a fenced per-subscription lock stored on the `subscriptions` document (`lock_token`/`lock_until`, expiry capped at the receipt lease); the receipt lease is fenced too. No ordering marker or rank logic remains.

### Finding map

| Finding | Change | Proving test |
|---|---|---|
| F1 BLOCKER same-second incomplete/active | Rank logic removed; projection always written from the freshly retrieved Stripe subscription (BillingGateway.php:233-281) | StripeBillingTest::test_real_checkout_sequence_reconciles_active_in_every_order_and_same_second (:80), test_cancel_sequence_and_recovery_follow_current_stripe_not_payload (:248) |
| F2 snapshot ordered by wrong event time | No event timestamps compared; per-subscription fenced lock serializes reconciliation (BillingGateway.php:206-231, fence :231) | same tests as F1, plus test_scheduled_cancel_keeps_pro_until_status_changes... (:419) |
| F3 sparse unique index with null | Migration `$unset`s null/empty `stripe_customer_id` then creates a partial unique index (`$type: string`) (migration :20, :25) | test_partial_customer_index_cleans_null_and_empty_values_and_preserves_existing_ids (:437), test_migration_is_idempotent_... (:273) |
| F4 customer creation recovery | createCustomer with immutable params (`metadata.user_id`) and idempotency key `vietfeed-customer-{id}`; email/name via best-effort updateCustomer (BillingGateway.php:30-50) | ProSubscriptionTest::test_customer_create_retry_after_mongo_failure_uses_immutable_parameters (:176), test_customer_profile_update_failure_does_not_block_checkout (:195) |
| F5 lease-expiry overlap | Lock expiry bounded by receipt lease; receipt re-checked before projection write; ack fenced by token and `$$NOW` (BillingGateway.php:155-171, :258-264) | test_abandoned_claim_is_retryable_after_lease_expiry (:184), test_retry_after_projection_before_acknowledgement_does_not_apply_twice (:156), test_real_concurrent_duplicate_delivery_applies_projection_once (:323) |
| F6 tie tests assert chosen rank | Tests now script Stripe snapshots and assert entitlement/cancel state for realistic lifecycles in both orders | tests listed under F1 |
| C1 admin withCount | Restored original withCount, ran ProSubscriptionTest: 500 `Object of class Illuminate\Database\Query\Expression could not be converted to string` at vendor/mongodb/laravel-mongodb/src/Query/Builder.php:434 (2 of 10 tests failed). User is a Mongo model; withCount emits a SQL-style subquery against SQL-resident comments/bookmarks/unlocks, which the Mongo builder cannot render. Manual grouped counts kept (UserController.php index) | ProSubscriptionTest::test_admin_filter_dashboard_and_page_entitlements_use_mongo |
| NEW lock contention -> 503 | Bounded wait: poll every LOCK_POLL_MS=150 ms up to LOCK_WAIT_MS=5000 ms (config `services.stripe.lock_wait_ms` overrides, used by tests), then 503 (BillingGateway.php:17-19, :214-230) | test_event_arriving_while_subscription_lock_is_held_waits_and_is_applied (:199), test_permanently_held_subscription_lock_yields_503_after_the_bound (:215) |
| MINOR applied_event_ids unbounded | `$push` with `$each`/`$slice: -50` (APPLIED_EVENTS_KEPT=50); `$ne event id` guard unchanged (BillingGateway.php:266, :277) | test_applied_event_ids_are_bounded_to_the_most_recent (:233) |

Note: the `$unset` of `ordering_marker`/`event_created`/`deleted` at BillingGateway.php:278 is legacy-field cleanup only, not ordering logic. Pint also reformatted StripeBillingTest.php, FakeStripeClient.php, BillingGateway.php and StripeSdkClient.php.

### Verification (real exit codes)

| Command | Exit | Result |
|---|---|---|
| phpunit StripeBillingTest x3 (after pint fix) | 0,0,0 | 19 tests / 340 assertions each |
| phpunit ProSubscriptionTest x3 | 0,0,0 | 10 / 125 |
| phpunit CatalogArticlePagesTest x3 | 0,0,0 | 16 / 308 |
| phpunit RedisStreamsTest x3 | 0,0,0 | 29 / 495 |
| composer run test (final) | 0 | 238 tests / 3921 assertions, no notices/warnings (baseline main 212 / 3475) |
| composer check-platform-reqs | 0 | |
| composer validate --no-check-publish | 0 | |
| vendor/bin/pint --test (all touched PHP) | 0 | after fixing 4 files; first run exit 1 |
| route:list --path=stripe / billing | - | POST stripe/webhook; POST billing/checkout, GET billing/portal, GET billing/success |
| grep Cashier/Billable/DB::table('subscriptions')/subscription_items/whereHas('subscriptions')/ordering_marker | - | only the legacy `$unset` string at BillingGateway.php:278 |
| git diff --stat composer.json composer.lock | - | 2 files, 3+/270-; removed only laravel/cashier (moneyphp/money, symfony/polyfill-intl-icu in lock); added direct stripe/stripe-php ^17.6 |

### Known limitations

- Lock contention beyond the 5 s bound still returns 503; live Stripe retries later, `stripe listen` does not.
- Orphan Stripe customer residual: if Stripe creates the customer and Mongo persistence fails, the idempotency key plus immutable params recover on retry within Stripe's key retention; after expiry an orphan customer may be created.
- A crashed lock holder blocks that subscription until its lock expires (at most the 60 s receipt lease).
- Real Stripe Test Mode acceptance has NOT been run.

STATUS: READY_FOR_REVIEW

## Fix round 2

| Item | Change | Test / evidence |
|---|---|---|
| R2-1 docs | Rewrote billing sections of docs/demo/billing-demo.md (inspection fields, design paragraph, `stripe events resend <evt_id>` recovery step, env-var-only secret handling) and docs/database/nosql-local.md (collection table, entitlement rule, webhook paragraph). No ordering_marker/rank/lexical/tombstone/sparse/"retry reclaims" wording remains | grep over both docs returns nothing |
| R2-2 stale worker fenced | Test uses FakeStripeClient::$onRetrieve to expire receipt lease and subscription lock and change the snapshot; asserts 503, no projection status, processed_at null, then a second delivery projects the new snapshot | StripeBillingTest::test_stale_worker_whose_lease_and_lock_expired_is_fenced_out_and_second_claimant_wins (:249) |
| R2-3 changing snapshots | incomplete then active across two serialized events, both delivery orders; asserts not Pro after the first, active and Pro after the second | test_stripe_state_changing_between_serialized_retrievals_converges_in_both_delivery_orders (:276) |
| R2-4 price check | BillingGateway::proUserIds adds `price_id = services.stripe.pro_price_id` when that config is non-empty (BillingGateway.php:101-107); empty falls back to type+status. ProSubscriptionTest fixtures now pin `price_gate7` (setUp and inserts) because the local env defines a price | test_entitlement_requires_the_configured_pro_price (:300): other price not Pro, pro price Pro, empty config falls back |
| R2-6 placeholder | unknown_user path deletes the placeholder only if it has no `user_id`, using the fence (BillingGateway.php:262) | test_unknown_user_and_ignored_events... (:118) now asserts 0 subscription docs |

Files changed this round: app/Billing/BillingGateway.php, tests/Feature/StripeBillingTest.php, tests/Feature/ProSubscriptionTest.php, docs/demo/billing-demo.md, docs/database/nosql-local.md, this report. Billing files overall also include app/Billing/SubscriptionMissing.php (one-line exception class, untracked, was missing from the review manifest).

Verification (exit codes): StripeBillingTest x3 0,0,0 (22 tests / 376 assertions); ProSubscriptionTest x3 0,0,0 (10 / 125); CatalogArticlePagesTest x3 0,0,0 (16 / 308); RedisStreamsTest x3 0,0,0 (29 / 495); `composer run test` 0, 241 tests / 3957 assertions, no warnings; check-platform-reqs 0; validate 0; pint --test on touched files 0; grep proof: only the legacy `$unset` string at BillingGateway.php:287; routes: POST stripe/webhook and the 3 billing routes; composer diff unchanged (2 files, 3+/270-).

STATUS: READY_FOR_REVIEW

## Control acceptance (2026-11-17, Claude Opus 5.5 control)

Workflow note: Codex quota ran out mid fix round 1 (resets 14:47). With owner approval, fix rounds 1–2 were finished by a
Claude Sonnet worker and round 1 was reviewed by an independent read-only Claude Opus reviewer (REVIEW-2.md, PASS_WITH_NOTES;
all notes addressed in fix round 2). Round 0 review by GPT 6.1 Terra: REVIEW-1.md (FAIL, resolved).

### Final gate (control, independent run)

| Check | Result |
|---|---|
| `composer run test` | exit 0 — 241 tests / 3957 assertions, no notices |
| `vendor/bin/pint --test` on all touched PHP files | exit 0 |
| `composer check-platform-reqs` | exit 0 |
| composer.lock diff (parsed old vs new) | removed exactly laravel/cashier, moneyphp/money, symfony/polyfill-intl-icu; nothing added/changed; stripe/stripe-php v17.6.0 same dist ref |
| Owner WIP sha256 (5 tracked files + pi-warden.md) | unchanged |
| `bootstrap/cache/config.php` | absent |

### Real Stripe Test Mode run (no browser)

Isolated stores: Mongo `vietfeed_test`, Redis DB15, throwaway SQLite file (deleted afterwards); app on `php artisan serve`
port 8077; `stripe listen --all-snapshot` (CLI 1.55.11, API version 2025-09-30.clover). Webhook secret from `.env` (matches
this PC's `stripe listen`).

1. Unsigned POST `/stripe/webhook` → 400.
2. `BillingGateway::checkout()` for a verified user → real Checkout Session URL on `checkout.stripe.com`; Stripe Customer
   created and stored as `stripe_customer_id`; second checkout reused the same customer. `portal()` → `billing.stripe.com` URL.
3. Subscription created through the Stripe API (`pm_card_visa`, `metadata[user_id]`, `metadata[type]=pro`): 14 events, all 200;
   `isPro()` true, status `active`, `price_id` = configured Pro price, lock released, 0 unprocessed receipts
   (13 `ignored`, 1 `projected`).
4. `cancel_at_period_end=true` → still Pro, `cancel_at_period_end` true, projection_version 2.
5. `stripe subscriptions cancel --confirm` → `canceled`, `isPro()` false, projection_version 3.
6. `stripe events resend` of the original `customer.subscription.created` → 200, receipt count unchanged (16), projection
   unchanged (version 3): duplicate is a no-op and the old event did not resurrect Pro.
7. Totals: 17 deliveries, 0 non-200. No `sk_test_`/`whsec_` values in `storage/logs/laravel.log`.
8. Cleanup: test customer deleted in Stripe; servers stopped.

Doc fix from this run: Stripe CLI 1.55 requires `--events`/`--all-snapshot` for `stripe listen` and `--confirm` for
`subscriptions cancel`; billing-demo.md updated.

### Browser acceptance (headless Chromium via Playwright, Claude Sonnet worker, 2026-11-17)

Same isolation (Mongo `vietfeed_test`, Redis DB15, throwaway SQLite, `SESSION_DRIVER=file`), app on port 8077,
`stripe listen --events checkout.session.completed,customer.subscription.{created,updated,deleted}`. Screenshots reviewed by control.

| Step | Result |
|---|---|
| a. Login as verified test user | PASS |
| b. Pro button → POST `/billing/checkout` → `checkout.stripe.com` (VietFeed Pro Monthly, ₫49,000/month, email prefilled) | PASS |
| c. Card 4242, 12/34, CVC 123, Subscribe (no 3DS) | PASS |
| d. Redirect to `/profile/reading-pass`: "Không giới hạn lượt mở bài", PRO badge, "Quản lý thanh toán"; Mongo status `active`, `isPro()` true, no unprocessed receipt | PASS |
| e. Navbar → `/billing/portal` → `billing.stripe.com`; "Cancel plan" confirmed → Stripe: canceled at period end (access until 2026-12-17) | PASS |
| f. After Portal cancel: still Pro, `cancel_at_period_end` true; after `stripe subscriptions cancel --confirm`: `canceled`, `isPro()` false, page shows "Còn 15 lượt" + "Nâng cấp Pro" | PASS |
| g. Webhook deliveries: 4 (`customer.subscription.created`, `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`), 4 × 200 | PASS |

Cleanup: test customer deleted in Stripe, only the started PIDs killed, throwaway SQLite deleted, config cache absent, git
status only owner WIP, 0 secret patterns in `storage/logs/laravel.log`. Environment note: the distro needed
`libnss3 libnspr4 libasound2t64` for Chromium (installed by the owner as root). The "Quản lý thanh toán" dropdown link stays
after cancellation because the Stripe customer remains (same as before Gate 7: it keys on the customer, not on Pro).

All Gate 7 acceptance items are now verified; nothing outstanding.
