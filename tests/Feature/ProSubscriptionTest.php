<?php

namespace Tests\Feature;

use App\Billing\BillingGateway;
use App\Billing\StripeClient;
use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use App\Services\ReadingPassService;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Tests\Doubles\FakeStripeClient;
use Tests\TestCase;

class ProSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.pro_price_id' => 'price_gate7']);
    }

    public function test_pricing_page_is_public(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('VietFeed Pro')
            ->assertSee('49.000₫');
    }

    public function test_unverified_user_cannot_start_checkout(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('billing.checkout'))
            ->assertRedirect();
    }

    public function test_active_pro_subscription_makes_reading_pass_unlimited(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->forceFill(['stripe_customer_id' => 'cus_test_123'])->save();

        DB::connection('mongodb')->table('subscriptions')->insert([
            'user_id' => $user->id,
            'type' => 'pro',
            'stripe_subscription_id' => 'sub_test_123',
            'status' => 'active',
            'price_id' => 'price_gate7',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $article = $this->article();
        $result = app(ReadingPassService::class)->accessOrLock($article, $user->fresh());

        $this->assertTrue($result['canAccess']);
        $this->assertSame('pro', $result['allowance']['tier']);
        $this->assertSame(-1, $result['allowance']['limit']);
    }

    public function test_checkout_creates_customer_and_reuses_it_with_required_metadata(): void
    {
        config(['services.stripe.pro_price_id' => 'price_gate7']);
        $fake = new FakeStripeClient;
        $this->app->instance(StripeClient::class, $fake);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/billing/checkout')->assertStatus(303)->assertRedirect('https://checkout.stripe.com/test_gate7');
        $this->post('/billing/checkout')->assertStatus(303);
        $this->assertCount(5, $fake->calls);
        $this->assertSame(['customer', ['metadata' => ['user_id' => (string) $user->id]], 'vietfeed-customer-'.$user->id], $fake->calls[0]);
        $params = $fake->calls[2][1];
        $this->assertSame('subscription', $params['mode']);
        $this->assertSame('cus_gate7', $params['customer']);
        $this->assertSame([['price' => 'price_gate7', 'quantity' => 1]], $params['line_items']);
        $this->assertSame((string) $user->id, $params['client_reference_id']);
        $this->assertSame(['user_id' => (string) $user->id, 'type' => 'pro'], $params['subscription_data']['metadata']);
        $this->assertSame(route('billing.success').'?session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
        $this->assertSame(route('pricing'), $params['cancel_url']);
        $this->assertSame($fake->calls[2], $fake->calls[4]);
        $this->assertSame(['update_customer', 'cus_gate7', ['email' => $user->email, 'name' => $user->name]], $fake->calls[1]);
        $this->assertFalse($user->fresh()->isPro());
    }

    public function test_portal_success_guards_and_stripe_outage_flashes(): void
    {
        $fake = new FakeStripeClient;
        $this->app->instance(StripeClient::class, $fake);
        $user = User::factory()->create();
        $this->actingAs($user)->get('/billing/portal')->assertRedirect(route('pricing'))->assertSessionHas('error', 'Bạn chưa có hồ sơ thanh toán Stripe.');
        config(['services.stripe.pro_price_id' => null]);
        $this->post('/billing/checkout')->assertSessionHas('error', 'Chưa cấu hình gói Stripe Pro. Kiểm tra STRIPE_PRO_PRICE_ID trong .env.');
        $user->forceFill(['stripe_customer_id' => 'cus_gate7'])->save();
        $this->get('/billing/portal')->assertStatus(303)->assertRedirect('https://billing.stripe.com/test_gate7');
        $this->assertSame(['portal', ['customer' => 'cus_gate7', 'return_url' => route('profile.reading-pass')]], $fake->calls[0]);
        $this->get('/billing/success?session_id=untrusted&status=active')->assertRedirect(route('profile.reading-pass'))->assertSessionHas('success');
        $this->assertFalse($user->fresh()->isPro());
        $fake->down = true;
        config(['services.stripe.pro_price_id' => 'price_gate7']);
        $this->post('/billing/checkout')->assertRedirect(route('pricing'))->assertSessionHas('error', 'Stripe hiện không khả dụng. Vui lòng thử lại sau.');
        $this->get('/billing/portal')->assertSessionHas('error', 'Stripe hiện không khả dụng. Vui lòng thử lại sau.');
        $this->get('/pricing')->assertOk();
        $this->get('/profile/reading-pass')->assertOk();
        DB::connection('mongodb')->table('subscriptions')->insert(['stripe_subscription_id' => 'sub_pro', 'user_id' => (string) $user->id, 'type' => 'pro', 'price_id' => 'price_gate7', 'status' => 'active']);
        $this->post('/billing/checkout')->assertRedirect(route('profile.reading-pass'))->assertSessionHas('success', 'Tài khoản của bạn đang dùng VietFeed Pro.');
    }

    public function test_only_active_or_trialing_pro_states_grant_entitlement(): void
    {
        $user = User::factory()->create();
        foreach (['active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'incomplete_expired', 'paused', 'canceled'] as $status) {
            DB::connection('mongodb')->table('subscriptions')->delete();
            DB::connection('mongodb')->table('subscriptions')->insert(['stripe_subscription_id' => 'sub_status', 'user_id' => (string) $user->id, 'type' => 'pro', 'price_id' => 'price_gate7', 'status' => $status]);
            $this->assertSame(in_array($status, ['active', 'trialing']), $user->isPro(), $status);
        }
        DB::connection('mongodb')->table('subscriptions')->update(['type' => 'other', 'status' => 'active']);
        $this->assertFalse($user->isPro());
    }

    public function test_admin_filter_dashboard_and_page_entitlements_use_mongo(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pro = User::factory()->create(['name' => 'Gate Seven Pro']);
        $free = User::factory()->create(['name' => 'Gate Seven Free']);
        foreach (['sub_one', 'sub_two'] as $id) {
            DB::connection('mongodb')->table('subscriptions')->insert(['stripe_subscription_id' => $id, 'user_id' => (string) $pro->id, 'type' => 'pro', 'price_id' => 'price_gate7', 'status' => 'trialing']);
        }
        $this->actingAs($admin)->get('/admin/users?plan=pro')->assertOk()->assertSee($pro->name)->assertDontSee($free->name);
        $this->get('/admin/users')->assertOk()->assertSee($free->name)->assertSee($pro->name);
        $this->get('/admin/users/'.$pro->id.'/edit')->assertOk()->assertSee('Pro');
        $this->get('/admin/dashboard')->assertOk()->assertViewHas('stats', fn ($stats) => $stats['pro_users'] === 1);
        $gateway = app(BillingGateway::class);
        $users = collect([$pro, $free]);
        $gateway->primeEntitlements($users);
        $this->assertTrue($pro->relationLoaded('billingEntitlement'));
        $this->assertTrue($pro->isPro());
        $this->assertFalse($free->isPro());
        $this->assertSame(1, count($gateway->proUserIds()));
    }

    public function test_admin_page_batches_subscription_reads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(19)->create();
        $subscriber = new class implements CommandSubscriber
        {
            public array $batches = [];

            public function commandStarted(CommandStartedEvent $event): void
            {
                $command = $event->getCommand();
                if ($event->getCommandName() === 'distinct' && ($command->distinct ?? null) === 'subscriptions') {
                    $this->batches[] = count($command->query->user_id->{'$in'} ?? []);
                }
            }

            public function commandSucceeded(CommandSucceededEvent $event): void {}

            public function commandFailed(CommandFailedEvent $event): void {}
        };
        \MongoDB\Driver\Monitoring\addSubscriber($subscriber);
        try {
            $response = $this->actingAs($admin)->get('/admin/users')->assertOk();
            $this->assertTrue($response->viewData('users')->getCollection()->every(fn ($user) => $user->relationLoaded('billingEntitlement')));
        } finally {
            \MongoDB\Driver\Monitoring\removeSubscriber($subscriber);
        }
        $this->assertSame(1, count(array_filter($subscriber->batches, fn ($size) => $size === 20)));
        $this->assertLessThanOrEqual(3, count($subscriber->batches));
    }

    public function test_customer_create_retry_after_mongo_failure_uses_immutable_parameters(): void
    {
        config(['services.stripe.pro_price_id' => 'price_gate7']);
        $fake = new FakeStripeClient;
        $this->app->instance(StripeClient::class, $fake);
        $conflict = User::factory()->create(['stripe_customer_id' => 'cus_gate7']);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/billing/checkout')->assertRedirect(route('pricing'))->assertSessionHas('error');
        $this->assertNull($user->fresh()->stripe_customer_id);
        DB::connection('mongodb')->getCollection('users')->updateOne(['email' => $conflict->email], ['$unset' => ['stripe_customer_id' => '']]);
        $user->forceFill(['name' => 'Changed after uncertain create', 'email' => 'changed@gate7.test'])->save();
        $this->actingAs($user->fresh())->post('/billing/checkout')->assertStatus(303);
        $creates = array_values(array_filter($fake->calls, fn ($call) => $call[0] === 'customer'));
        $this->assertCount(2, $creates);
        $this->assertSame($creates[0], $creates[1]);
        $this->assertSame(['customer', ['metadata' => ['user_id' => (string) $user->id]], 'vietfeed-customer-'.$user->id], $creates[0]);
        $this->assertSame('cus_gate7', $user->fresh()->stripe_customer_id);
    }

    public function test_customer_profile_update_failure_does_not_block_checkout(): void
    {
        config(['services.stripe.pro_price_id' => 'price_gate7']);
        $fake = new FakeStripeClient;
        $fake->updateDown = true;
        $this->app->instance(StripeClient::class, $fake);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/billing/checkout')->assertStatus(303)->assertRedirect('https://checkout.stripe.com/test_gate7');
        $updates = array_values(array_filter($fake->calls, fn ($call) => $call[0] === 'update_customer'));
        $this->assertSame([['update_customer', 'cus_gate7', ['email' => $user->email, 'name' => $user->name]]], $updates);
    }

    private function article(): Article
    {
        $category = Category::create(['name' => 'Thời sự', 'slug' => 'thoi-su']);
        $source = Source::create([
            'name' => 'Nguồn test',
            'url' => 'https://example.com',
            'feed_url' => 'https://example.com/rss',
            'category_id' => $category->id,
            'is_active' => true,
            'prestige' => 1,
        ]);

        return Article::create([
            'source_id' => $source->id,
            'category_id' => $category->id,
            'title' => 'Bài test Pro',
            'slug' => 'bai-test-pro',
            'description' => 'Nội dung test',
            'original_url' => 'https://example.com/bai-test-pro',
            'published_at' => now(),
        ]);
    }
}
