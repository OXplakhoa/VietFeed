<?php

namespace Tests\Feature;

use App\Billing\BillingGateway;
use App\Billing\StripeClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\Doubles\FakeStripeClient;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => 'whsec_gate7_only', 'services.stripe.pro_price_id' => 'price_gate7']);
        $this->app->instance(StripeClient::class, new FakeStripeClient);
    }

    private function event(string $type = 'customer.subscription.created', int $created = 100, string $id = 'evt_gate7', array $changes = []): array
    {
        return ['id' => $id, 'object' => 'event', 'created' => $created, 'type' => $type, 'data' => ['object' => array_replace_recursive([
            'id' => 'sub_gate7', 'object' => 'subscription', 'customer' => 'cus_gate7', 'status' => 'active',
            'metadata' => ['user_id' => User::first()?->id, 'type' => 'pro'],
            'items' => ['data' => [['price' => ['id' => 'price_gate7'], 'current_period_end' => 200]]],
            'cancel_at_period_end' => false, 'canceled_at' => null, 'ended_at' => null,
        ], $changes)]];
    }

    private function deliver(array $event, ?string $signature = 'valid', ?int $timestamp = null)
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $headers['HTTP_STRIPE_SIGNATURE'] = $signature === 'valid'
                ? 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_gate7_only') : $signature;
        }

        return $this->call('POST', '/stripe/webhook', [], [], [], $headers, $payload);
    }

    public function test_signed_create_duplicate_and_delete_project_entitlement(): void
    {
        $user = User::factory()->create();
        $event = $this->event();
        app(StripeClient::class)->subscription = $event['data']['object'];
        $this->deliver($event)->assertOk();
        $this->deliver($event)->assertOk();
        $this->assertTrue($user->fresh()->isPro());
        $this->assertSame('cus_gate7', $user->fresh()->stripe_customer_id);
        $projection = DB::connection('mongodb')->table('subscriptions')->first();
        $this->assertSame(1, $projection->projection_version);
        $this->assertSame(200, $projection->current_period_end);
        app(StripeClient::class)->subscription = $this->event(changes: ['status' => 'canceled', 'ended_at' => 101])['data']['object'];
        $this->deliver($this->event('customer.subscription.deleted', 101, 'evt_delete', ['status' => 'canceled', 'ended_at' => 101]))->assertOk();
        $this->assertFalse($user->fresh()->isPro());
        $this->assertSame(2, DB::connection('mongodb')->table('processed_stripe_events')->whereNotNull('processed_at')->count());
    }

    public function test_invalid_missing_and_expired_signatures_have_no_side_effects(): void
    {
        User::factory()->create();
        foreach (['invalid', null] as $signature) {
            $this->deliver($this->event(), $signature)->assertStatus(400);
        }
        $this->deliver($this->event(), timestamp: time() - 301)->assertStatus(400);
        $this->assertSame(0, DB::connection('mongodb')->table('processed_stripe_events')->count());
        $this->assertSame(0, DB::connection('mongodb')->table('subscriptions')->count());
    }

    public function test_real_checkout_sequence_reconciles_active_in_every_order_and_same_second(): void
    {
        $user = User::factory()->create();
        $fake = app(StripeClient::class);
        $fake->subscription = $this->event()['data']['object'];
        foreach ([[100, 100, 100], [100, 101, 102]] as $times) {
            $created = $this->event('customer.subscription.created', $times[0], 'evt_created', ['status' => 'incomplete']);
            $updated = $this->event('customer.subscription.updated', $times[1], 'evt_updated');
            $checkout = $this->event('checkout.session.completed', $times[2], 'evt_checkout');
            $checkout['data']['object'] = ['mode' => 'subscription', 'subscription' => 'sub_gate7'];
            foreach ([[$created, $updated, $checkout], [$created, $checkout, $updated], [$updated, $created, $checkout], [$updated, $checkout, $created], [$checkout, $created, $updated], [$checkout, $updated, $created]] as $order) {
                foreach (['subscriptions', 'processed_stripe_events'] as $name) {
                    DB::connection('mongodb')->table($name)->delete();
                }
                foreach ($order as $event) {
                    $this->deliver($event)->assertOk();
                    $this->assertTrue($user->fresh()->isPro());
                }
                $this->assertSame('active', DB::connection('mongodb')->table('subscriptions')->first()->status);
            }
        }
    }

    public function test_checkout_completion_fetches_subscription_and_falls_back_to_customer(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_gate7']);
        $fake = $this->app->make(StripeClient::class);
        $fake->subscription = $this->event()['data']['object'];
        $fake->subscription['metadata'] = [];
        $event = $this->event('checkout.session.completed', 100, 'evt_checkout');
        $event['data']['object'] = ['id' => 'cs_gate7', 'mode' => 'subscription', 'subscription' => 'sub_gate7'];
        $this->deliver($event)->assertOk();
        $this->assertSame([['retrieve', 'sub_gate7']], $fake->calls);
        $this->assertTrue($user->fresh()->isPro());
        $this->deliver($event)->assertOk();
        $this->assertCount(1, $fake->calls);
    }

    public function test_unknown_user_and_ignored_events_are_recorded_without_entitlement(): void
    {
        app(StripeClient::class)->subscription = $this->event(changes: ['metadata' => ['user_id' => 'unknown']])['data']['object'];
        $this->deliver($this->event(changes: ['metadata' => ['user_id' => 'unknown']]))->assertOk();
        $this->deliver($this->event('invoice.paid', 100, 'evt_ignored'))->assertOk();
        $events = DB::connection('mongodb')->table('processed_stripe_events');
        $this->assertSame('unknown_user', $events->where('_id', 'evt_gate7')->value('result'));
        $this->assertSame('ignored', DB::connection('mongodb')->table('processed_stripe_events')->where('_id', 'evt_ignored')->value('result'));
        $this->assertSame(0, DB::connection('mongodb')->table('subscriptions')->whereNotNull('status')->count());
        $this->assertSame(0, DB::connection('mongodb')->table('subscriptions')->count());
    }

    public function test_malformed_signed_json_and_incomplete_envelope_are_rejected(): void
    {
        $payload = '{';
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_gate7_only');
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
        $this->deliver(['id' => 'evt_bad', 'object' => 'event', 'type' => 'invoice.paid', 'data' => ['object' => []]])->assertStatus(400);
        $this->assertSame(0, DB::connection('mongodb')->table('processed_stripe_events')->count());
    }

    public function test_retry_after_retrieval_failure_is_not_marked_processed(): void
    {
        $user = User::factory()->create();
        $fake = $this->app->make(StripeClient::class);
        $fake->subscription = $this->event()['data']['object'];
        $event = $this->event('checkout.session.completed');
        $event['data']['object'] = ['mode' => 'subscription', 'subscription' => 'sub_gate7'];
        $fake->down = true;
        $this->deliver($event)->assertStatus(503);
        $receipt = DB::connection('mongodb')->table('processed_stripe_events')->first();
        $this->assertNull($receipt->processed_at);
        $this->assertFalse($user->fresh()->isPro());
        $fake->down = false;
        $this->deliver($event)->assertOk();
        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_retry_after_projection_before_acknowledgement_does_not_apply_twice(): void
    {
        $user = User::factory()->create();
        app(StripeClient::class)->subscription = $this->event()['data']['object'];
        $gateway = new class($this->app->make(StripeClient::class)) extends BillingGateway
        {
            public bool $fail = true;

            protected function apply(array $event, string $receiptToken, UTCDateTime $receiptExpiry): string
            {
                $result = parent::apply($event, $receiptToken, $receiptExpiry);
                if ($this->fail) {
                    $this->fail = false;
                    throw new \RuntimeException('Synthetic interruption before acknowledgement');
                }

                return $result;
            }
        };
        $this->app->instance(BillingGateway::class, $gateway);
        $this->deliver($this->event())->assertStatus(503);
        $this->assertNull(DB::connection('mongodb')->table('processed_stripe_events')->first()->processed_at);
        $this->assertTrue($user->fresh()->isPro());
        $this->deliver($this->event())->assertOk();
        $this->assertSame(1, DB::connection('mongodb')->table('subscriptions')->first()->projection_version);
        $this->assertNotNull(DB::connection('mongodb')->table('processed_stripe_events')->first()->processed_at);
    }

    public function test_abandoned_claim_is_retryable_after_lease_expiry(): void
    {
        $user = User::factory()->create();
        app(StripeClient::class)->subscription = $this->event()['data']['object'];
        DB::connection('mongodb')->table('processed_stripe_events')->insert([
            '_id' => 'evt_gate7', 'type' => 'customer.subscription.created', 'processed_at' => null,
            'claim' => 'abandoned', 'lease_until' => new UTCDateTime((time() + 3600) * 1000),
        ]);
        $this->deliver($this->event())->assertStatus(503);
        $this->assertFalse($user->fresh()->isPro());
        DB::connection('mongodb')->table('processed_stripe_events')->where('_id', 'evt_gate7')->update(['lease_until' => new UTCDateTime(0)]);
        $this->deliver($this->event())->assertOk();
        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_event_arriving_while_subscription_lock_is_held_waits_and_is_applied(): void
    {
        $user = User::factory()->create();
        app(StripeClient::class)->subscription = $this->event()['data']['object'];
        config(['services.stripe.lock_wait_ms' => 4000]);
        // A held lock that expires ~0.5 s from now (the holder finishing its burst event).
        DB::connection('mongodb')->table('subscriptions')->insert([
            '_id' => 'sub_gate7', 'stripe_subscription_id' => 'sub_gate7', 'lock_token' => 'other',
            'lock_until' => new UTCDateTime((int) (microtime(true) * 1000) + 500),
        ]);
        $started = microtime(true);
        $this->deliver($this->event())->assertOk();
        $this->assertGreaterThan(0.2, microtime(true) - $started);
        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_permanently_held_subscription_lock_yields_503_after_the_bound(): void
    {
        $user = User::factory()->create();
        app(StripeClient::class)->subscription = $this->event()['data']['object'];
        config(['services.stripe.lock_wait_ms' => 300]);
        DB::connection('mongodb')->table('subscriptions')->insert([
            '_id' => 'sub_gate7', 'stripe_subscription_id' => 'sub_gate7', 'lock_token' => 'other',
            'lock_until' => new UTCDateTime((time() + 3600) * 1000),
        ]);
        $started = microtime(true);
        $this->deliver($this->event())->assertStatus(503);
        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(0.3, $elapsed);
        $this->assertLessThan(3, $elapsed);
        $this->assertFalse($user->fresh()->isPro());
        $this->assertNull(DB::connection('mongodb')->table('processed_stripe_events')->first()->processed_at);
    }

    public function test_applied_event_ids_are_bounded_to_the_most_recent(): void
    {
        $user = User::factory()->create();
        app(StripeClient::class)->subscription = $this->event()['data']['object'];
        $keep = BillingGateway::APPLIED_EVENTS_KEPT;
        for ($i = 1; $i <= $keep + 3; $i++) {
            $this->deliver($this->event(id: 'evt_n'.$i))->assertOk();
        }
        $ids = (array) DB::connection('mongodb')->table('subscriptions')->first()->applied_event_ids;
        $this->assertCount($keep, $ids);
        $this->assertSame('evt_n'.($keep + 3), end($ids));
        $this->assertNotContains('evt_n1', $ids);
        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_stale_worker_whose_lease_and_lock_expired_is_fenced_out_and_second_claimant_wins(): void
    {
        $user = User::factory()->create();
        $fake = app(StripeClient::class);
        $fake->subscription = $this->event(changes: ['status' => 'incomplete'])['data']['object'];
        $first = true;
        $fake->onRetrieve = function () use (&$first) {
            if (! $first) {
                return;
            }
            $first = false;
            $zero = new UTCDateTime(0);
            DB::connection('mongodb')->table('processed_stripe_events')->where('_id', 'evt_gate7')->update(['lease_until' => $zero]);
            DB::connection('mongodb')->table('subscriptions')->where('_id', 'sub_gate7')->update(['lock_until' => $zero]);
            app(StripeClient::class)->subscription = $this->event(changes: ['status' => 'active'])['data']['object'];
        };
        $this->deliver($this->event())->assertStatus(503);
        $projection = DB::connection('mongodb')->table('subscriptions')->first();
        $this->assertObjectNotHasProperty('status', $projection);
        $this->assertFalse($user->fresh()->isPro());
        $this->assertNull(DB::connection('mongodb')->table('processed_stripe_events')->first()->processed_at);
        $this->deliver($this->event())->assertOk();
        $this->assertTrue($user->fresh()->isPro());
        $this->assertSame('active', DB::connection('mongodb')->table('subscriptions')->first()->status);
        $this->assertNotNull(DB::connection('mongodb')->table('processed_stripe_events')->first()->processed_at);
    }

    public function test_stripe_state_changing_between_serialized_retrievals_converges_in_both_delivery_orders(): void
    {
        foreach ([['created', 'updated'], ['updated', 'created']] as $n => $order) {
            DB::connection('mongodb')->table('subscriptions')->delete();
            DB::connection('mongodb')->table('processed_stripe_events')->delete();
            User::query()->delete();
            $user = User::factory()->create();
            $fake = app(StripeClient::class);
            $fake->onRetrieve = function () use ($fake, $user) {
                $fake->subscription = $this->event(changes: ['status' => 'active', 'metadata' => ['user_id' => $user->id]])['data']['object'];
            };
            // The fake snapshots before onRetrieve runs: retrieval 1 sees incomplete, later ones active.
            $fake->subscription = $this->event(changes: ['status' => 'incomplete', 'metadata' => ['user_id' => $user->id]])['data']['object'];
            foreach ($order as $i => $kind) {
                $this->deliver($this->event('customer.subscription.'.$kind, 100, 'evt_'.$n.'_'.$kind, ['metadata' => ['user_id' => $user->id]]))->assertOk();
                if ($i === 0) {
                    $this->assertFalse($user->fresh()->isPro());
                }
            }
            $this->assertSame('active', DB::connection('mongodb')->table('subscriptions')->first()->status);
            $this->assertTrue($user->fresh()->isPro());
        }
    }

    public function test_entitlement_requires_the_configured_pro_price(): void
    {
        $user = User::factory()->create();
        $fake = app(StripeClient::class);
        $fake->subscription = $this->event(changes: ['items' => ['data' => [['price' => ['id' => 'price_other']]]]])['data']['object'];
        $this->deliver($this->event())->assertOk();
        $this->assertFalse($user->fresh()->isPro());
        $fake->subscription = $this->event()['data']['object'];
        $this->deliver($this->event(id: 'evt_pro'))->assertOk();
        $this->assertTrue($user->fresh()->isPro());
        // Without a configured price the type+status rule alone applies.
        config(['services.stripe.pro_price_id' => '']);
        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_cancel_sequence_and_recovery_follow_current_stripe_not_payload(): void
    {
        $user = User::factory()->create();
        $fake = app(StripeClient::class);
        $scheduled = $this->event('customer.subscription.updated', 100, 'evt_scheduled', ['cancel_at_period_end' => true]);
        $deleted = $this->event('customer.subscription.deleted', 100, 'evt_deleted', ['status' => 'canceled']);
        foreach ([[$scheduled, $deleted], [$deleted, $scheduled]] as $order) {
            foreach (['subscriptions', 'processed_stripe_events'] as $name) {
                DB::connection('mongodb')->table($name)->delete();
            }
            $fake->subscription = $this->event(changes: ['status' => 'canceled', 'cancel_at_period_end' => false, 'ended_at' => 200])['data']['object'];
            foreach ($order as $event) {
                $this->deliver($event)->assertOk();
                $this->assertFalse($user->fresh()->isPro());
                $this->assertFalse(DB::connection('mongodb')->table('subscriptions')->first()->cancel_at_period_end);
            }
        }
        $fake->subscription = $this->event(changes: ['status' => 'past_due'])['data']['object'];
        $this->deliver($this->event('customer.subscription.updated', 300, 'evt_due'))->assertOk();
        $this->assertFalse($user->fresh()->isPro());
        $fake->subscription = $this->event()['data']['object'];
        $this->deliver($this->event('customer.subscription.updated', 300, 'evt_recovery', ['status' => 'past_due']))->assertOk();
        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_migration_is_idempotent_backfills_without_replacing_new_id_and_creates_unique_indexes(): void
    {
        $legacy = User::factory()->create();
        $legacy->forceFill(['stripe_id' => 'cus_legacy'])->save();
        $current = User::factory()->create(['stripe_customer_id' => 'cus_current']);
        $current->forceFill(['stripe_id' => 'cus_old'])->save();
        $migration = require database_path('migrations/2026_11_17_000001_create_mongodb_billing_indexes.php');
        $migration->up();
        $migration->up();
        $this->assertSame('cus_legacy', $legacy->fresh()->stripe_customer_id);
        $this->assertSame('cus_legacy', $legacy->fresh()->stripe_id);
        $this->assertSame('cus_current', $current->fresh()->stripe_customer_id);
        $mongo = DB::connection('mongodb');
        foreach (['users' => 'stripe_customer_id_1', 'subscriptions' => 'stripe_subscription_id_1', 'processed_stripe_events' => '_id_'] as $collection => $name) {
            $indexes = iterator_to_array($mongo->getCollection($collection)->listIndexes());
            $index = collect($indexes)->first(fn ($index) => $index->getName() === $name);
            $this->assertNotNull($index);
            if ($name !== '_id_') {
                $this->assertTrue($index->isUnique());
            }
        }
        foreach ([['users', ['_id' => 'duplicate-user', 'email' => 'unique@gate7.test', 'stripe_customer_id' => 'cus_current']], ['subscriptions', ['stripe_subscription_id' => 'sub_duplicate']], ['processed_stripe_events', ['_id' => 'evt_duplicate']]] as [$collection, $document]) {
            if ($collection !== 'users') {
                $mongo->getCollection($collection)->insertOne($document);
            }
            try {
                $mongo->getCollection($collection)->insertOne($document);
                $this->fail('Unique billing identity must reject duplicates');
            } catch (BulkWriteException $e) {
                $this->assertSame(11000, $e->getCode());
            }
        }
    }

    public function test_webhook_and_entitlement_have_no_sql_or_redis_dependency(): void
    {
        $user = User::factory()->create();
        // Gate 9.5 slice 5: there is no SQL connection at all (the former SQL billing tables cannot exist).
        $this->assertSame(['mongodb'], array_keys(config('database.connections')));
        app(StripeClient::class)->subscription = $this->event()['data']['object'];
        $default = config('database.default');
        config(['database.default' => 'nonexistent', 'session.driver' => 'database', 'cache.default' => 'redis']);
        try {
            $this->deliver($this->event())->assertOk();
            $this->assertTrue($user->fresh()->isPro());
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_real_concurrent_duplicate_delivery_applies_projection_once(): void
    {
        $user = User::factory()->create();
        $event = $this->event();
        $worker = <<<'WORKER'
        require getcwd().'/vendor/autoload.php';
        foreach (['APP_ENV'=>'testing','MONGO_DB'=>'vietfeed_test','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','TELESCOPE_ENABLED'=>'false','NIGHTWATCH_ENABLED'=>'false'] as $key=>$value) {
            putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value;
        }
        if (file_exists(getcwd().'/bootstrap/cache/config.php')) { exit(10); }
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if (config('database.connections.mongodb.database') !== 'vietfeed_test' || config('database.default') !== 'mongodb' || array_keys(config('database.connections')) !== ['mongodb']) { exit(11); }
        foreach (['nosql','default','cache'] as $name) { config(['database.redis.'.$name.'.url'=>null,'database.redis.'.$name.'.database'=>15]); }
        config(['services.stripe.webhook_secret'=>'whsec_gate7_only']);
        // Bind the fake explicitly; no worker is allowed to use Stripe networking.
        $app->instance(\App\Billing\StripeClient::class, new \Tests\Doubles\FakeStripeClient);
        echo "ready\n"; fflush(STDOUT);
        $payload = trim(fgets(STDIN));
        $app->make(\App\Billing\StripeClient::class)->subscription = json_decode($payload, true)['data']['object'];
        $t = time();
        $signature = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$payload, 'whsec_gate7_only');
        $request = \Illuminate\Http\Request::create('/stripe/webhook', 'POST', [], [], [], ['CONTENT_TYPE'=>'application/json','HTTP_STRIPE_SIGNATURE'=>$signature], $payload);
        $response = $app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);
        echo $response->getStatusCode();
        WORKER;
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, '-r', $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [, $pipes]) {
                stream_set_timeout($pipes[1], 20);
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], json_encode($event)."\n");
                fclose($pipes[0]);
            }
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $statuses[] = (int) stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process));
                $this->assertSame('', $errors);
            }
            sort($statuses);
            $this->assertContains($statuses, [[200, 200], [200, 503]]);
            app(StripeClient::class)->subscription = $event['data']['object'];
            $this->deliver($event)->assertOk();
            $this->assertTrue($user->fresh()->isPro());
            $this->assertSame(1, DB::connection('mongodb')->table('subscriptions')->first()->projection_version);
            $this->assertSame(1, DB::connection('mongodb')->table('processed_stripe_events')->whereNotNull('processed_at')->count());
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }

    public function test_billing_failures_log_only_sanitized_context(): void
    {
        $user = User::factory()->create();
        $fake = $this->app->make(StripeClient::class);
        $fake->down = true;
        $logs = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context) use (&$logs) {
            $logs[] = [$message, $context];
        });
        $this->actingAs($user)->post('/billing/checkout')->assertSessionHas('error');
        $event = $this->event('checkout.session.completed');
        $event['data']['object'] = ['mode' => 'subscription', 'subscription' => 'sub_gate7'];
        $this->deliver($event)->assertStatus(503);
        $text = json_encode($logs);
        $this->assertNotEmpty($logs);
        foreach (['sensitive synthetic diagnostic', 'whsec_gate7_only', 'Stripe-Signature', $user->email, 'subscription_data', 'sk_test_'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        foreach ($logs as [, $context]) {
            $this->assertSame([], array_diff(array_keys($context), ['operation', 'exception']));
        }
    }

    public function test_scheduled_cancel_keeps_pro_until_status_changes_and_new_subscription_can_restore_it(): void
    {
        $user = User::factory()->create();
        app(StripeClient::class)->subscription = $this->event(changes: ['cancel_at_period_end' => true, 'canceled_at' => 90])['data']['object'];
        $this->deliver($this->event('customer.subscription.updated', 100, 'evt_scheduled', ['cancel_at_period_end' => true, 'canceled_at' => 90]))->assertOk();
        $this->assertTrue($user->fresh()->isPro());
        $projection = DB::connection('mongodb')->table('subscriptions')->first();
        $this->assertTrue($projection->cancel_at_period_end);
        $this->assertSame(200, $projection->ends_at);
        $this->assertSame(90, $projection->canceled_at);
        app(StripeClient::class)->subscription = $this->event(changes: ['status' => 'canceled', 'ended_at' => 200])['data']['object'];
        $this->deliver($this->event('customer.subscription.deleted', 200, 'evt_ended', ['ended_at' => 200]))->assertOk();
        $this->assertFalse($user->fresh()->isPro());
        app(StripeClient::class)->subscription = $this->event(changes: ['id' => 'sub_new', 'status' => 'trialing'])['data']['object'];
        $this->deliver($this->event('customer.subscription.created', 201, 'evt_new_subscription', ['id' => 'sub_new', 'status' => 'trialing']))->assertOk();
        $this->assertTrue($user->fresh()->isPro());
    }

    public function test_partial_customer_index_cleans_null_and_empty_values_and_preserves_existing_ids(): void
    {
        $users = DB::connection('mongodb')->getCollection('users');
        foreach ($users->listIndexes() as $index) {
            if ($index->getName() === 'stripe_customer_id_1') {
                $users->dropIndex('stripe_customer_id_1');
            }
        }
        $a = User::factory()->create(['stripe_customer_id' => null]);
        $b = User::factory()->create(['stripe_customer_id' => null]);
        $empty = User::factory()->create(['stripe_customer_id' => '']);
        $legacy = User::factory()->create(['stripe_customer_id' => null]);
        $legacy->forceFill(['stripe_id' => 'cus_legacy_round1'])->save();
        $current = User::factory()->create(['stripe_customer_id' => 'cus_preserved']);
        $current->forceFill(['stripe_id' => 'cus_other'])->save();
        $migration = require database_path('migrations/2026_11_17_000001_create_mongodb_billing_indexes.php');
        $migration->up();
        $migration->up();
        foreach ([$a, $b, $empty] as $user) {
            $this->assertArrayNotHasKey('stripe_customer_id', $user->fresh()->getAttributes());
        }
        $this->assertSame('cus_legacy_round1', $legacy->fresh()->stripe_customer_id);
        $this->assertSame('cus_preserved', $current->fresh()->stripe_customer_id);
        $index = collect(iterator_to_array($users->listIndexes()))->first(fn ($index) => $index->getName() === 'stripe_customer_id_1');
        $this->assertTrue($index->isUnique());
        $this->assertSame(['stripe_customer_id' => ['$type' => 'string']], json_decode(json_encode($index['partialFilterExpression']), true));
        // Partial uniqueness excludes explicit nulls even on documents added later.
        User::factory()->count(2)->create(['stripe_customer_id' => null]);
        $migration->up();
    }
}
