# Gate 7 — Stripe-to-Mongo billing

Canonical sources, in precedence order: `docs/specs/vietfeed-nosql-storylens.md` (§2 items 49/54, §7 stories 15, 16, 30,
§11 "Billing Gateway", §12.3, §15, §16 item 9), `docs/planning/technical-gates.md` Gate 7,
`docs/planning/project-scope-notes.md` "Billing and Stripe", `docs/architecture/database-feature-map.md` (Billing/Stripe and
Reading Pass rows, Mongo collections `subscriptions`, `processed_stripe_events`).

One gate, one branch, one PR: branch `billing/stripe-mongo`, base `main` `0d2f878`.

Goal: the existing user flow Pricing → Stripe Test Checkout → signed webhook → Pro activation → Reading Pass benefits →
Stripe Billing Portal keeps working against real Stripe Test Mode, with MongoDB as the only project database on the billing
critical path. Laravel Cashier's SQL-backed persistence is gone, not hidden.

## Current state (recon by control, 2026-11-17)

- `App\Models\User` (Mongo, `MongoDB\Laravel\Auth\User`) uses `Laravel\Cashier\Billable`; `isPro()` = `subscribed('pro')`,
  which reads Cashier's SQL `subscriptions` / `subscription_items` tables (default connection SQLite).
- `BillingController` uses `newSubscription('pro', …)->checkout(…)` and `redirectToBillingPortal(…)`; webhook is Cashier's
  auto-registered `POST /stripe/webhook`. Cashier reads `metadata[type]` to name the subscription.
- SQL call sites: `Admin\DashboardController` (pro_users count), `Admin\UserController` (Pro filter), `tests/Feature/ProSubscriptionTest.php`,
  `tests/Feature/RedisStreamsTest.php` (counts `DB::table('subscriptions')`), `tests/Feature/CatalogArticlePagesTest.php`
  (`load('subscriptions')`). Views call `isPro()`, `stripe_id`, `stripeCustomerUrl()`.
- Cashier migrations: `database/migrations/2026_06_03_23315{5..9}_*`; `config/cashier.php`; `config/services.php` `stripe.pro_price_id`.
- `laravel/cashier` v16.5.3 pulls `stripe/stripe-php` v17.6.0 (already locked).
- Env names in use: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_PRO_PRICE_ID`, `STRIPE_WEBHOOK_SECRET` (test-mode values in `.env`,
  webhook secret matches `stripe listen` on this PC).
- A real test-mode run on 2026-11-16 (Cashier version) passed: 15 webhook events 200, `isPro()` flips on create/cancel.

## Required outcomes

1. **Billing Gateway (domain module)** — one module (e.g. `App\Billing\…`) owning: create Checkout Session, create Billing
   Portal session, verify + receive webhook, apply subscription projection, answer entitlement (`isPro`). Controllers, views,
   admin queries and Reading Pass go through it; nothing else talks to Stripe or the billing collections. Stripe access sits
   behind an interface so tests use a fake without network.
2. **Remove Cashier persistence** — drop `Billable`, the `laravel/cashier` dependency, `config/cashier.php` and the five Cashier
   migrations; require `stripe/stripe-php` directly at the already-locked major (`^17.6`), no other new dependency. No code
   path may read or write SQL `subscriptions`, `subscription_items` or SQL customer columns. A test (or grep-based assertion
   in the gate report) proves it.
3. **MongoDB projection** —
   - Stripe customer id on the User document as `stripe_customer_id` (sparse unique index). Backfill: an idempotent migration
     copies legacy `stripe_id` → `stripe_customer_id` on Mongo users (do not delete the old field in this gate; say so).
   - Collection `subscriptions`: one document per Stripe subscription (`stripe_subscription_id` unique), with `user_id` (UUIDv7
     string of the User), `type` (`pro`), customer id, status, price id, current period end, cancel-at-period-end,
     `ends_at`/cancelled info, and the ordering marker used for out-of-order handling. Index on `user_id`.
   - Collection `processed_stripe_events`: Stripe event id unique (`_id` or unique index), type, received/processed timestamps.
   - Indexes created by migration (pattern of `database/migrations/2026_09_22_000001_create_mongodb_user_indexes.php`).
4. **Checkout** — `POST /billing/checkout` (verified users only, as today) creates or reuses the Stripe Customer (metadata
   `user_id` = VietFeed UUID), creates a subscription-mode Checkout Session for `STRIPE_PRO_PRICE_ID` with
   `client_reference_id` and `subscription_data.metadata` = `user_id` + `type=pro`, and redirects (303) to Stripe. Keeps the
   current guards and Vietnamese flash messages (no price configured; already Pro → reading-pass page).
   `GET /billing/success` may retrieve the Checkout Session to show a faster result, but must apply state only through the same
   idempotent projection path and must not trust query parameters without asking Stripe.
5. **Portal** — `GET /billing/portal` opens a Billing Portal session for `stripe_customer_id`, return URL reading-pass page;
   same flash when there is no customer.
6. **Signed webhook** — `POST /stripe/webhook` (same path, so `stripe listen` and the Dashboard endpoint keep working), CSRF
   exempt, verifies the `Stripe-Signature` with `STRIPE_WEBHOOK_SECRET` and Stripe's default tolerance; invalid/missing
   signature → 400 without side effects; malformed payload → 400. Handles at least `checkout.session.completed`,
   `customer.subscription.created|updated|deleted`; other events → 200 and recorded as ignored. Resolves the user by
   subscription metadata `user_id`, then by `stripe_customer_id`; unknown user → 200 recorded, logged without payload secrets.
7. **Idempotency** — a duplicate event id is a no-op (200). A failure between receipt and projection must not leave the event
   marked processed: a Stripe retry of the same event must still apply it. Concurrent delivery of the same event applies at
   most once. Prove each with tests.
8. **Out-of-order** — an older event can never overwrite newer subscription state (e.g. `updated(active)` arriving after
   `deleted` must not resurrect Pro). Choose the ordering rule (event `created` and/or re-fetching the subscription from Stripe
   through the gateway interface), justify it in the report, and test ties and reversed pairs.
9. **Entitlement** — `User::isPro()` (keep the method name: views and Reading Pass use it) is true iff the user has a `pro`
   subscription in Mongo with status `active` or `trialing` (same as Cashier's default; state the rule). Reading Pass Pro tier,
   comment Pro badge, navbar, profile, admin user list/filter/edit and dashboard `pro_users` all read the Mongo projection.
   Avoid N+1 on the admin list (one query for the page's users).
10. **Failure behaviour (story 30)** — if Stripe is unreachable, checkout/portal redirect back with a Vietnamese error flash and
    log without secrets; pages that only show Pro state keep working from Mongo.
11. **No secrets** — no keys, webhook secret, signatures or full payloads in logs, exceptions, reports or commits. `.env.example`
    keeps placeholders only. Do not edit the active `.env`.
12. **Tests** — feature tests in `composer run test` (Mongo `vietfeed_test`, SQLite `:memory:`, Redis DB15 guard unchanged),
    no network: signature valid/invalid/missing/expired-tolerance, each handled event type, duplicate event, retry after
    mid-processing failure, concurrent duplicate, out-of-order pairs, unknown user, ignored type, checkout redirect +
    customer reuse (fake gateway asserting params incl. metadata), portal, success page, Stripe-down flashes, isPro/Reading Pass,
    admin filter/count, and absence of SQL billing tables usage. Update the three existing tests that touch SQL subscriptions.
    Webhook test payloads are signed in the test with a test-only secret.
13. **Docs** — `docs/demo/billing-demo.md`: real Stripe Test Mode walkthrough (`stripe listen --forward-to …/stripe/webhook`,
    card 4242, Pro on, Portal cancel, Pro off) and `mongosh` inspection of `subscriptions` / `processed_stripe_events`;
    update `docs/database/nosql-local.md` billing collections; `GATE-REPORT-7.md` in this folder.

## Test isolation (hard requirements)

Mongo `vietfeed_test`, SQLite `:memory:`, Redis DB15 behind the existing live `getDbNum()` guard. No Cassandra or Neo4j needed.
Never touch dev Redis DB0/1/2, never `migrate:fresh` dev data, never `docker compose down -v`, never `FLUSHALL`.
Run mutating suites sequentially.

## Non-goals

Account deletion lifecycle and Stripe cancellation on deletion (later gate; do not break current deletion), Cassandra billing
audit, coupons/taxes/multiple plans, admin editing subscriptions, changing price/amount/pricing copy, any SPA or Blade redesign,
removing the remaining non-billing SQL runtime, deleting the legacy `stripe_id` field.

## Stop conditions

Product-scope change required; need to edit owner WIP; a failure not fixable in scope; dependency resolution that would change
anything beyond removing `laravel/cashier` and requiring `stripe/stripe-php`; container restart or OOM.
