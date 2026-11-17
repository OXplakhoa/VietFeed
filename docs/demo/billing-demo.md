# Gate 7 — real Stripe Test Mode walkthrough

This is the reviewer/control acceptance procedure. The implementation worker did
not execute it. Automated tests use a fake Stripe client and signed local fixtures.
Stripe is authoritative; Mongo owns VietFeed's subscription projection. There is
no Cashier persistence. Pricing copy and the configured Stripe Price are unchanged.

## Preparation

Use the healthy existing local stack and a verified local user. In private local
configuration, set `STRIPE_KEY` (test publishable key), `STRIPE_SECRET` (test secret
key), `STRIPE_PRO_PRICE_ID` (the existing recurring Test Mode Price), and
`STRIPE_WEBHOOK_SECRET`. Never paste keys, signatures or full events into reports.
The publishable key remains a supported environment name; hosted Checkout itself
uses the server-side secret and does not need browser Stripe.js.

Apply only the new Mongo migration, once, before starting the walkthrough:

```bash
php artisan migrate --database=mongodb --path=database/migrations/2026_11_17_000001_create_mongodb_billing_indexes.php
```

It creates billing indexes and copies nonempty legacy `users.stripe_id` to missing
or null `stripe_customer_id`. Existing new IDs and the old field are preserved.
No SQL migration, reset or data deletion is needed. Duplicate pre-existing customer
IDs must be investigated rather than bypassing the unique index. The worker proved
rerunning the migration on `vietfeed_test`, and did not run it on development data.

With the application at `http://127.0.0.1:8000`, run in a separate terminal:

```bash
stripe listen --events checkout.session.completed,customer.subscription.created,customer.subscription.updated,customer.subscription.deleted --forward-to http://127.0.0.1:8000/stripe/webhook
```

Stripe CLI 1.55+ refuses to start without `--events` (or `--all-snapshot`, which also
works: unhandled types are acknowledged and recorded as `ignored`). Without a browser,
the API equivalents used in the 2026-11-17 acceptance were `stripe subscriptions update
<sub> -d cancel_at_period_end=true` and `stripe subscriptions cancel <sub> --confirm`
(the CLI asks for confirmation interactively otherwise).

Configure the listener's signing secret privately; it differs from a Dashboard
endpoint secret. Keep secret-bearing listener startup output out of shared logs.
If configuration was cached, clear it after the private configuration change.
For a remote endpoint, subscribe to `checkout.session.completed` and
`customer.subscription.created`, `customer.subscription.updated`,
`customer.subscription.deleted` in the Stripe Dashboard.

## Checkout → Pro → Portal → cancellation

1. Sign in as a verified user and open `/pricing`. Start Checkout. It returns a 303
   redirect to real Stripe Test Checkout, creating/reusing one Stripe Customer with
   VietFeed UUID metadata. Session `client_reference_id` is that UUID; subscription
   metadata contains `user_id` and `type=pro`.
2. Pay using Test Mode card `4242 4242 4242 4242`, any future expiry, any three-digit
   CVC and the test form's requested billing details. Do not enter real card data.
3. Return to `/billing/success`, then `/profile/reading-pass`. The success URL only
   displays a pending webhook message; query parameters never activate Pro.
   After the signed webhook succeeds, refresh: Pro shows unlimited Reading Pass,
   navbar/profile Pro state and the comment Pro badge. Admin user filter/count
   and user edit/list must agree with this user's projection.
4. Open `/billing/portal`. Confirm the real Stripe Portal opens for the same
   Customer and returns to the Reading Pass page.
5. Cancel in the Portal. If cancellation is scheduled at period end, the current
   subscription remains `active` and Pro remains on until it actually ends. To
   demonstrate Pro off immediately, use the Test Mode Dashboard to cancel the
   subscription immediately, or advance a Stripe Test Clock through period end
   when using a clock-backed customer. Wait for `customer.subscription.deleted`
   to return 200, then refresh Reading Pass: free allowance and no Pro badge.
6. In Test Mode Dashboard webhook delivery history, resend a successfully handled
   subscription event. Expect 200 with unchanged `projection_version`. Do not replay
   a production event. An in-flight duplicate can return 503 and must be retried;
   a processed duplicate always returns 200.
   Recovery: under `stripe listen`, a 503'd event is NOT retried. Re-send it with
   `stripe events resend <evt_id>` (live Dashboard endpoints retry automatically).
   Supply the listener secret only through an environment variable
   (`stripe listen --print-secret` into `STRIPE_WEBHOOK_SECRET` of your local
   environment); never echo or paste it.
7. With Stripe connectivity temporarily unavailable (without stopping local
   containers), a new Checkout/Portal attempt shows the Vietnamese retry flash.
   Pricing, profile and Reading Pass continue reading the stored Mongo entitlement.

Record only sanitized observations: response codes, status transitions, test IDs,
projection versions and counts. The final real acceptance belongs to control.

## Read-only Mongo inspection

Connect interactively using your local credentials, so no password is placed in
shell history or report output:

```bash
docker exec -it vietfeed-mongo mongosh --authenticationDatabase admin --username <local-mongo-user> --password
```

Inside mongosh (development database `vietfeed`; use `vietfeed_test` only for
isolated automated fixtures):

```javascript
use vietfeed
db.users.getIndexes()
db.subscriptions.getIndexes()
db.processed_stripe_events.getIndexes()
// Substitute the user's canonical UUID, not an email or secret.
db.users.find({_id: "<user-uuid>"}, {_id: 1, stripe_customer_id: 1})
db.subscriptions.find({user_id: "<user-uuid>"}, {
  _id: 1, user_id: 1, type: 1, status: 1, price_id: 1,
  current_period_end: 1, cancel_at_period_end: 1, ends_at: 1,
  canceled_at: 1, cancel_at: 1, event_id: 1, applied_event_ids: 1,
  lock_until: 1, projection_version: 1
})
db.processed_stripe_events.find({}, {
  _id: 1, type: 1, received_at: 1, processed_at: 1, lease_until: 1, result: 1
}).sort({received_at: -1}).limit(20)
```

A subscription `_id` is the Stripe subscription ID (also unique `stripe_subscription_id`),
with indexed `user_id` (canonical User UUID). `applied_event_ids` keeps only the last
50 applied event IDs; `projection_version` increments per applied event. `lock_token`
and `lock_until` are present only while a worker reconciles that subscription.
Event `_id` is the Stripe event ID, unique by Mongo itself. Receipts have no raw
payload/signature; `claim` and `lease_until` appear only while processing, and
`processed_at` is null for unfinished work. `result` is `projected`, `ignored` or
`unknown_user`. A crash can leave a claim/lock for at most 60 seconds. There is no
manual deletion of receipts to retry normal failures.

Design: the webhook payload is only a trigger. Every handled event retrieves the
current subscription from Stripe while holding the per-subscription lock stored on
the subscription document, then writes that state, so no event ordering is needed.
A second event for a busy subscription waits (polling, 5 seconds total) for the
holder, then returns 503 if still busy. `users.stripe_customer_id` has a partial
unique index (`$type: string`). Pro requires `type=pro`, status `active`/`trialing`
and `price_id` equal to `STRIPE_PRO_PRICE_ID`; if that variable is empty only
type and status are checked. Retain receipts for this gate; no cleanup job exists.
