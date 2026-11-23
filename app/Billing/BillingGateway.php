<?php

namespace App\Billing;

use App\Accounts\AccountLifecycle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;
use Stripe\Webhook;
use Throwable;

class BillingGateway
{
    public const LOCK_WAIT_MS = 5000;

    public const LOCK_POLL_MS = 150;

    public const APPLIED_EVENTS_KEPT = 50;

    public function __construct(private StripeClient $stripe) {}

    private function collection(string $name): Collection
    {
        return DB::connection('mongodb')->getCollection($name);
    }

    public function checkout(User $user): string
    {
        return $this->stripeOperation('checkout', function () use ($user) {
            $customer = $user->stripe_customer_id;
            if (! $customer) {
                // Stable across retries and simultaneous Checkouts for the same user.
                $customer = $this->stripe->createCustomer([
                    'metadata' => ['user_id' => (string) $user->id],
                ], 'vietfeed-customer-'.$user->id);
                User::where('_id', $user->id)->whereNull('stripe_customer_id')->update(['stripe_customer_id' => $customer]);
                $customer = User::findOrFail($user->id)->stripe_customer_id;
                $user->stripe_customer_id = $customer;
            }

            try {
                $this->stripe->updateCustomer($customer, ['email' => $user->email, 'name' => $user->name]);
            } catch (Throwable $e) {
                Log::warning('Billing customer profile update unavailable', ['exception' => $e::class]);
            }

            return $this->stripe->createCheckout([
                'mode' => 'subscription', 'customer' => $customer,
                'line_items' => [['price' => config('services.stripe.pro_price_id'), 'quantity' => 1]],
                'client_reference_id' => (string) $user->id,
                'subscription_data' => ['metadata' => ['user_id' => (string) $user->id, 'type' => 'pro']],
                'success_url' => route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('pricing'),
            ]);
        });
    }

    public function portal(User $user): string
    {
        return $this->stripeOperation('portal', fn () => $this->stripe->createPortal([
            'customer' => $user->stripe_customer_id, 'return_url' => route('profile.reading-pass'),
        ]));
    }

    private function stripeOperation(string $operation, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            // Never forward SDK messages, traces, payloads or exception chains.
            Log::warning('Billing operation unavailable', ['operation' => $operation, 'exception' => $e::class]);
            throw new BillingUnavailable('Billing temporarily unavailable');
        }
    }

    public function customerUrl(User $user): ?string
    {
        if (! $user->stripe_customer_id) {
            return null;
        }

        return 'https://dashboard.stripe.com/'.(str_starts_with((string) config('services.stripe.secret'), 'sk_test_') ? 'test/' : '')
            .'customers/'.rawurlencode($user->stripe_customer_id);
    }

    public function isPro(User $user): bool
    {
        // Admin page snapshots are request-local relations, never persisted attributes.
        if ($user->relationLoaded('billingEntitlement')) {
            return $user->getRelation('billingEntitlement');
        }

        return in_array((string) $user->id, $this->proUserIds([(string) $user->id]), true);
    }

    public function proUserIds(?array $userIds = null): array
    {
        $filter = ['type' => 'pro', 'status' => ['$in' => ['active', 'trialing']]];
        // Only the configured Pro price grants Pro; with no configured price the
        // type+status rule alone applies (dev/tests without STRIPE_PRO_PRICE_ID).
        $price = config('services.stripe.pro_price_id');
        if (is_string($price) && $price !== '') {
            $filter['price_id'] = $price;
        }
        if ($userIds !== null) {
            $filter['user_id'] = ['$in' => $userIds];
        }

        return $this->collection('subscriptions')->distinct('user_id', $filter);
    }

    public function primeEntitlements(iterable $users): void
    {
        $users = collect($users);
        $ids = $this->proUserIds($users->map(fn ($user) => (string) $user->id)->all());
        foreach ($users as $user) {
            $user->setRelation('billingEntitlement', in_array((string) $user->id, $ids, true));
        }
    }

    public function receive(#[\SensitiveParameter] string $payload, #[\SensitiveParameter] ?string $signature): void
    {
        $secret = config('services.stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new BillingUnavailable('Webhook configuration unavailable');
        }
        try {
            $event = Webhook::constructEvent($payload, $signature ?? '', $secret)->toArray();
            if (! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)
                || ! is_int($event['created'] ?? null) || ! is_array($event['data']['object'] ?? null)) {
                throw new \UnexpectedValueException;
            }
        } catch (Throwable) {
            throw new InvalidWebhook('Invalid Stripe webhook');
        }

        $this->process($event);
    }

    private function process(array $event): void
    {
        $events = $this->collection('processed_stripe_events');
        $token = bin2hex(random_bytes(16));
        try {
            $events->updateOne(['_id' => $event['id']], ['$setOnInsert' => [
                'type' => $event['type'], 'received_at' => new UTCDateTime, 'processed_at' => null,
            ]], ['upsert' => true]);
        } catch (BulkWriteException $e) {
            if ($e->getCode() !== 11000) {
                throw $e;
            }
        }
        $claim = $events->findOneAndUpdate([
            '_id' => $event['id'], 'processed_at' => null,
            '$expr' => ['$lte' => [['$ifNull' => ['$lease_until', new UTCDateTime(0)]], '$$NOW']],
        ], [['$set' => ['claim' => $token, 'lease_until' => ['$add' => ['$$NOW', 60000]]]]], [
            'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ]);
        if ($claim === null) {
            if ($events->findOne(['_id' => $event['id'], 'processed_at' => ['$ne' => null]])) {
                return;
            }
            throw new BillingUnavailable('Webhook processing in progress');
        }
        try {
            $result = $this->apply($event, $token, $claim['lease_until']);
            $ack = $events->updateOne([
                '_id' => $event['id'], 'claim' => $token,
                '$expr' => ['$gt' => ['$lease_until', '$$NOW']],
            ], [
                '$set' => ['processed_at' => new UTCDateTime, 'result' => $result],
                '$unset' => ['claim' => '', 'lease_until' => ''],
            ]);
            if ($ack->getModifiedCount() !== 1) {
                throw new BillingUnavailable('Receipt lease lost');
            }
        } catch (Throwable $e) {
            $events->updateOne(['_id' => $event['id'], 'claim' => $token], ['$unset' => ['claim' => '', 'lease_until' => '']]);
            Log::warning('Billing webhook processing failed', ['exception' => $e::class]);
            throw new BillingUnavailable('Webhook processing unavailable');
        }
    }

    protected function apply(array $event, string $receiptToken, UTCDateTime $receiptExpiry): string
    {
        $type = $event['type'];
        if (! in_array($type, ['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            return 'ignored';
        }
        $trigger = $event['data']['object'];
        if ($type === 'checkout.session.completed') {
            if (($trigger['mode'] ?? null) !== 'subscription' || empty($trigger['subscription'])) {
                return 'ignored';
            }
            $id = is_array($trigger['subscription']) ? ($trigger['subscription']['id'] ?? null) : $trigger['subscription'];
        } else {
            $id = $trigger['id'] ?? null;
        }
        if (! is_string($id) || $id === '') {
            throw new \UnexpectedValueException('Invalid subscription identity');
        }
        $subscriptions = $this->collection('subscriptions');
        try {
            $subscriptions->updateOne(['_id' => $id], ['$setOnInsert' => ['stripe_subscription_id' => $id]], ['upsert' => true]);
        } catch (BulkWriteException $e) {
            if ($e->getCode() !== 11000) {
                throw $e;
            }
        }
        $token = bin2hex(random_bytes(16));
        // The lock and projection share one document: fencing is atomic. Its
        // lifetime cannot exceed the receipt lease, so an expired receipt owner
        // cannot write even in the gap between the ownership read and update.
        // A Checkout burst (created/updated/completed) arrives nearly at once and
        // `stripe listen` never retries, so wait for the holder instead of 503-ing.
        // Each holder re-fetches current Stripe state, so serializing is sufficient.
        $deadline = microtime(true) + max(0, (int) config('services.stripe.lock_wait_ms', self::LOCK_WAIT_MS)) / 1000;
        while (true) {
            $lock = $subscriptions->updateOne([
                '_id' => $id,
                '$expr' => ['$and' => [
                    ['$lte' => [['$ifNull' => ['$lock_until', new UTCDateTime(0)]], '$$NOW']],
                    ['$gt' => [$receiptExpiry, '$$NOW']],
                ]],
            ], ['$set' => ['lock_token' => $token, 'lock_until' => $receiptExpiry]]);
            if ($lock->getModifiedCount() === 1) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new BillingUnavailable('Subscription reconciliation busy');
            }
            usleep(self::LOCK_POLL_MS * 1000);
        }
        $fence = ['_id' => $id, 'lock_token' => $token, '$expr' => ['$gt' => ['$lock_until', '$$NOW']]];
        try {
            try {
                $subscription = $this->stripe->retrieveSubscription($id);
            } catch (SubscriptionMissing $e) {
                if ($type !== 'customer.subscription.deleted') {
                    throw $e;
                }
                // Only a confirmed missing resource on deletion permits payload fallback.
                $subscription = array_replace($trigger, ['status' => 'canceled']);
            }
            if (($subscription['id'] ?? null) !== $id) {
                throw new \UnexpectedValueException('Stripe subscription identity mismatch');
            }
            $customer = $subscription['customer'] ?? null;
            $customer = is_array($customer) ? ($customer['id'] ?? null) : $customer;
            if (! is_string($customer) || ! is_string($subscription['status'] ?? null)) {
                throw new \UnexpectedValueException('Invalid subscription shape');
            }
            $userId = $subscription['metadata']['user_id'] ?? null;
            $user = is_string($userId) ? User::find($userId) : null;
            $user ??= User::where('stripe_customer_id', $customer)->first();
            // Gate 9.7b: once an account is being purged or gone, billing rows stay reconcilable under a pseudonym.
            // A live user model read before the purge finished must never write the real id back.
            $gone = null;
            if ($user && (($user->getAttribute('deletion')['state'] ?? null) === AccountLifecycle::PURGING
                || $subscriptions->findOne($fence + ['account_purged' => true]) !== null)) {
                $gone = AccountLifecycle::pseudonym((string) $user->id);
                $user = null;
            }
            if (! $user && $gone === null) {
                $gone = $this->goneAccount($subscriptions, $fence, is_string($userId) ? $userId : null);
                if ($gone === null) {
                    Log::notice('Billing webhook user not found', ['event_id' => $event['id']]);
                    // Drop only the placeholder this attempt upserted (never projected).
                    $subscriptions->deleteOne($fence + ['user_id' => ['$exists' => false]]);

                    return 'unknown_user';
                }
            }
            $receipt = $this->collection('processed_stripe_events')->findOne([
                '_id' => $event['id'], 'claim' => $receiptToken, 'processed_at' => null,
                '$expr' => ['$gt' => ['$lease_until', '$$NOW']],
            ]);
            if ($receipt === null) {
                throw new BillingUnavailable('Receipt lease lost');
            }
            $periodEnd = $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? null;
            $result = $subscriptions->updateOne($fence + ['applied_event_ids' => ['$ne' => $event['id']]] + ($user ? ['account_purged' => ['$ne' => true]] : []), [
                '$set' => [
                    'user_id' => $user ? (string) $user->id : $gone, 'type' => $subscription['metadata']['type'] ?? 'pro',
                    'stripe_customer_id' => $customer, 'status' => $subscription['status'],
                    'price_id' => $subscription['items']['data'][0]['price']['id'] ?? null,
                    'current_period_end' => $periodEnd,
                    'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
                    'canceled_at' => $subscription['canceled_at'] ?? null, 'cancel_at' => $subscription['cancel_at'] ?? null,
                    'ends_at' => $subscription['ended_at'] ?? $subscription['cancel_at'] ?? (! empty($subscription['cancel_at_period_end']) ? $periodEnd : null),
                    'event_id' => $event['id'], 'updated_at' => new UTCDateTime,
                ] + ($user ? [] : ['account_purged' => true]),
                '$push' => ['applied_event_ids' => ['$each' => [$event['id']], '$slice' => -self::APPLIED_EVENTS_KEPT]],
                '$inc' => ['projection_version' => 1],
                '$unset' => ['ordering_marker' => '', 'event_created' => '', 'deleted' => ''],
            ]);
            if ($result->getModifiedCount() !== 1 && $subscriptions->findOne($fence + ['applied_event_ids' => $event['id']]) === null) {
                throw new BillingUnavailable('Subscription lease lost');
            }
            if ($user) {
                User::where('_id', $user->id)->whereNull('stripe_customer_id')->update(['stripe_customer_id' => $customer]);
                $this->cancelForPendingAccount($user, $subscription);
            }

            return 'projected';
        } finally {
            $subscriptions->updateOne(['_id' => $id, 'lock_token' => $token], ['$unset' => ['lock_token' => '', 'lock_until' => '']]);
        }
    }

    /** Pseudonym to project under when the owner is being purged or purged, else null (a genuinely unknown user). */
    private function goneAccount(Collection $subscriptions, array $fence, ?string $metadataUserId): ?string
    {
        $existing = $subscriptions->findOne($fence + ['account_purged' => true]);
        if ($existing !== null && is_string($existing['user_id'] ?? null)) {
            return $existing['user_id'];
        }
        if ($metadataUserId !== null && app(AccountLifecycle::class)->isPurging($metadataUserId)) {
            return AccountLifecycle::pseudonym($metadataUserId);
        }

        return null;
    }

    /** A subscription that became billable for an account awaiting deletion (e.g. a Checkout finished late) is canceled again. */
    private function cancelForPendingAccount(User $user, array $subscription): void
    {
        if ($user->isPendingDeletion() && in_array($subscription['status'], AccountLifecycle::BILLABLE, true)) {
            try {
                $this->stripe->cancelSubscription($subscription['id'], 'vietfeed-delete-cancel-'.$subscription['id']);
            } catch (Throwable $e) {
                // The purge worker repeats the cancellation (final step), so the webhook must still succeed.
                Log::warning('Billing cancel for pending deletion deferred', ['exception' => $e::class]);
            }
        }
    }
}
