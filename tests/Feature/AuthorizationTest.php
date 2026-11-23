<?php

namespace Tests\Feature;

use App\ApiTokens\ApiTokenService;
use App\Authorization\AdminRoutes;
use App\Authorization\AuthorizationAudit;
use App\Authorization\AuthorizationConflict;
use App\Authorization\Permissions;
use App\Authorization\SuperAdminGuard;
use App\Authorization\UserAuthorization;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Sanction;
use App\Models\Source;
use App\Models\User;
use App\Moderation\ModerationException;
use App\Moderation\ModerationService;
use App\StoryProcessing\CassandraClient;
use Closure;
use Database\Seeders\UserSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as AdminRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    public function test_every_admin_route_has_an_enforced_ability_for_every_role_and_permission(): void
    {
        $target = User::factory()->create();
        $article = Article::create(['title' => 'Article', 'slug' => 'article', 'original_url' => 'https://example.test/article']);
        $category = Category::create(['name' => 'Category', 'slug' => 'category']);
        $source = Source::create(['name' => 'Source', 'url' => 'https://example.test', 'feed_url' => 'https://example.test/rss']);
        $comment = Comment::create(['article_id' => $article->id, 'user_id' => $target->id, 'body' => 'Comment']);
        $ids = [
            'article' => $article->id, 'category' => $category->id, 'source' => $source->id, 'comment' => $comment->id,
            'user' => $target->id, 'story' => (string) Str::uuid7(), 'report' => (string) Str::uuid7(), 'sanction' => (string) Str::uuid7(),
        ];
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (AdminRoute $route) => $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            // Keep real routing, model binding and auth, then stop before any side effects.
            $route->middleware(AdminActionProbe::class);
            $route->flushController();
            $name = $route->getName();
            $ability = AdminRoutes::ability($name);
            $this->assertContains($ability, [Permissions::STAFF, Permissions::SUPER, ...Permissions::ALL], "$name must have a mapped ability");
            $this->assertContains('admin', $route->gatherMiddleware(), $name);
            $this->assertContains('can:'.$ability, $route->gatherMiddleware(), $name);
        }

        $actors = [
            'guest' => null,
            'user' => $target,
            'no permissions' => $this->admin([]),
            ...array_combine(Permissions::ALL, array_map(fn ($permission) => $this->admin([$permission]), Permissions::ALL)),
            'super admin' => $this->superAdmin(),
        ];
        foreach ($actors as $identity => $actor) {
            if ($actor !== null) {
                $this->actingAs($actor);
            }
            foreach ($routes as $route) {
                $name = $route->getName();
                $ability = AdminRoutes::ability($name);
                $url = route($name, array_intersect_key($ids, array_flip($route->parameterNames())));
                $status = match (true) {
                    $actor === null => 302,
                    $actor->isUser() => 403,
                    $identity === 'super admin', $ability === Permissions::STAFF, $identity === $ability => 204,
                    default => 403,
                };
                foreach ($route->methods() as $method) {
                    $response = $this->call($method, $url);
                    $this->assertSame($status, $response->status(), "$identity $method $url ($name)");
                    if ($actor === null) {
                        $response->assertRedirect(route('login'));
                    }
                }
            }
        }
    }

    public function test_target_policy_protects_staff_and_privileged_fields_and_filters_the_ui(): void
    {
        $this->schema();
        $manager = $this->admin([Permissions::USERS]);
        $target = User::factory()->create();
        $super = $this->superAdmin();
        $anotherAdmin = $this->admin([]);
        $this->actingAs($manager)->get(route('admin.users.edit', $target))->assertOk()
            ->assertDontSee('name="role"', false)->assertDontSee('name="permissions[]"', false)
            ->assertDontSee(route('admin.sanctions.store'), false);
        $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.authorization.audit'), false);
        foreach ([$super, $anotherAdmin] as $staff) {
            $this->get(route('admin.users.edit', $staff))->assertForbidden();
            $this->patchJson(route('admin.users.update', $staff), $this->profile($staff))->assertForbidden();
            $this->deleteJson(route('admin.users.destroy', $staff))->assertForbidden();
        }
        foreach ([['role' => 'super_admin'], ['role' => null], ['permissions' => []], ['permissions' => [Permissions::USERS]]] as $input) {
            $this->patchJson(route('admin.users.update', $target), $this->profile($target, $input))->assertForbidden();
        }
        $audit = app(AuthorizationAudit::class);
        foreach ([$super, $anotherAdmin] as $staff) {
            $events = $audit->forUser($staff->id);
            $this->assertCount(3, $events);
            $this->assertSame(['privilege_change_refused'], array_values(array_unique(array_column($events, 'reason'))));
            $this->assertSame([$manager->id], array_values(array_unique(array_column($events, 'actor_id'))));
        }
        $this->assertCount(4, $audit->forUser($target->id));
        $this->put(route('admin.users.update', $target), $this->profile($target, ['name' => 'Renamed']))->assertRedirect();
        $this->assertSame('Renamed', $target->refresh()->name);
        $this->assertTrue($target->isUser());
        $target->fill(['role' => 'super_admin', 'permissions' => Permissions::ALL])->save();
        $this->assertTrue($target->refresh()->isUser());
        $this->assertSame([], $target->permissions);
        $this->actingAs($target)->patch(route('profile.update'), ['name' => $target->name, 'email' => $target->email, 'role' => 'super_admin'])
            ->assertRedirect();
        $this->assertTrue($target->refresh()->isUser());

        $this->actingAs($super)->get(route('admin.users.edit', $anotherAdmin))->assertOk()
            ->assertSee('name="role"', false)->assertSee('name="permissions[]"', false);
        $this->patchJson(route('admin.users.update', $target), $this->profile($target, ['role' => 'admin', 'permissions' => ['arbitrary.manage']]))
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->patchJson(route('admin.users.update', $target), $this->profile($target, ['role' => 'user', 'permissions' => [Permissions::USERS]]))
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
    }

    public function test_remaining_super_admin_cannot_be_demoted_or_deleted_by_either_path(): void
    {
        $this->schema();
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $this->actingAs($first)->patch(route('admin.users.update', $first), $this->profile($first, ['role' => 'admin']))
            ->assertRedirect(route('admin.dashboard'));
        $this->assertFalse($first->refresh()->isSuperAdmin());
        $this->actingAs($second)->patchJson(route('admin.users.update', $second), $this->profile($second, ['role' => 'user']))->assertConflict();
        $this->deleteJson(route('admin.users.destroy', $second))->assertConflict();
        $this->deleteJson(route('profile.destroy'), ['password' => 'password'])->assertConflict();
        $this->assertAuthenticatedAs($second);
        $this->assertTrue($second->refresh()->isSuperAdmin());
        // Gate 9.7b: deletion no longer touches the Super Admin guard. Staff must be demoted first, so only the demotion is audited here.
        $events = app(AuthorizationAudit::class)->forUser($second->id);
        $this->assertCount(1, $events);
        $this->assertSame(['super_admin_removal_refused'], array_unique(array_column($events, 'reason')));
        $message = 'Không thể hạ quyền của Super Admin cuối cùng.';
        $deletion = 'Hãy gỡ quyền quản trị trước khi xóa tài khoản.';
        $edit = route('admin.users.edit', $second);
        $this->from($edit)->patch(route('admin.users.update', $second), $this->profile($second, ['role' => 'user']))
            ->assertRedirect($edit)->assertSessionHas('error', $message);
        $this->from($edit)->delete(route('admin.users.destroy', $second))
            ->assertRedirect($edit)->assertSessionHas('error', $deletion);
        $this->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('profile.edit'))->assertSessionHas('error', $deletion)->assertSessionMissing('_old_input.password');
        $this->assertNull($second->fresh()->getAttribute('deletion'));
    }

    public function test_two_removals_interleaved_between_the_guard_and_user_write_keep_one_super_admin(): void
    {
        $this->schema();
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $realGuard = app(SuperAdminGuard::class);
        $competing = app(UserAuthorization::class);
        $guard = $this->partialMock(SuperAdminGuard::class);
        $guard->shouldReceive('reserveRemoval')->once()->with((string) $first->id)->andReturnUsing(function ($id) use ($realGuard, $competing, $second) {
            $this->assertTrue($realGuard->reserveRemoval($id));
            $this->assertSame(2, User::where('role', 'super_admin')->count(), 'Both contenders still see two user documents');
            try {
                $competing->edit($second, $second, [], 'user', []);
                $this->fail('The second reservation must be refused.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }

            return true;
        });
        app(UserAuthorization::class)->edit($first, $first, [], 'user', []);
        $this->assertSame([$second->id], User::where('role', 'super_admin')->pluck('id')->all());
    }

    public function test_failed_user_write_keeps_guard_conservative_until_repair(): void
    {
        $this->schema();
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        User::where('_id', $first->id)->update(['api_token_epoch' => 'invalid-epoch']);
        try {
            app(UserAuthorization::class)->edit($first, $first, [], 'user', []);
            $this->fail('The atomic epoch increment must reject the invalid stored value.');
        } catch (BulkWriteException) {
            $this->assertTrue($first->refresh()->isSuperAdmin());
        }
        $this->actingAs($second)->deleteJson(route('admin.users.destroy', $second))->assertConflict();
        $this->assertTrue($second->refresh()->isSuperAdmin());
    }

    public function test_permanent_ban_cannot_disable_super_admin_access_and_banned_user_cannot_be_promoted(): void
    {
        $this->schema();
        $super = $this->superAdmin();
        $moderator = $this->admin([Permissions::MODERATION]);
        $target = User::factory()->create();
        $this->actingAs($moderator)->from(route('admin.sanctions.index'))->post(route('admin.sanctions.store'), [
            'user_id' => $super->id, 'type' => 'permanent_ban', 'reason' => 'Fixture',
        ])->assertSessionHas('error');
        $this->assertFalse($super->isPermanentlyBanned());
        $this->post(route('admin.sanctions.store'), [
            'user_id' => $target->id, 'type' => 'permanent_ban', 'reason' => 'Fixture',
        ])->assertSessionHas('success');
        $this->actingAs($super)->patchJson(route('admin.users.update', $target), $this->profile($target, ['role' => 'super_admin']))->assertConflict();
        $this->assertTrue($target->refresh()->isUser());
    }

    public function test_revocation_takes_effect_on_the_next_web_request_and_revokes_access_and_refresh_tokens(): void
    {
        $this->schema();
        $super = $this->superAdmin();
        $admin = $this->admin([Permissions::CONTENT, Permissions::USERS]);
        $tokens = $this->partialMock(ApiTokenService::class);
        $tokens->shouldReceive('cleanupForUser')->twice()->with((string) $admin->id);
        $old = $tokens->issue($admin);
        $this->actingAs($admin)->get(route('admin.articles.index'))->assertOk();
        $this->actingAs($super)->patch(route('admin.users.update', $admin), $this->profile($admin, [
            'role' => 'admin', 'permissions' => [Permissions::USERS],
        ]))->assertRedirect();
        $this->assertRevoked($old);
        $this->resumeWebSession($admin)->get(route('admin.articles.index'))->assertForbidden();
        $this->get(route('admin.users.index'))->assertOk();

        $fresh = $tokens->issue($admin->refresh());
        $this->actingAs($super)->patch(route('admin.users.update', $admin), $this->profile($admin, ['role' => 'user']))->assertRedirect();
        $this->assertRevoked($fresh);
        $this->resumeWebSession($admin)->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_bootstrap_refuses_unknown_email_grants_legacy_access_once_and_is_audited(): void
    {
        $this->schema();
        $initial = $this->admin([]);
        $legacy = $this->admin([]);
        User::where('_id', $legacy->id)->toBase()->drop('permissions');
        $plain = User::factory()->create();
        $this->artisan('rbac:bootstrap', ['--super-admin' => 'unknown@example.test'])->assertFailed();
        $this->assertFalse($initial->refresh()->isSuperAdmin());
        $this->artisan('rbac:bootstrap', ['--super-admin' => $initial->email])
            ->expectsOutput('Promoted: 1. Legacy admins granted permissions: 1.')->assertSuccessful();
        $this->assertTrue($initial->refresh()->isSuperAdmin());
        $this->assertSame(Permissions::ALL, $legacy->refresh()->permissions);
        $this->assertTrue($plain->refresh()->isUser());
        $this->actingAs($initial)->patch(route('admin.users.update', $legacy), $this->profile($legacy, ['role' => 'admin', 'permissions' => []]))
            ->assertRedirect();
        $this->artisan('rbac:bootstrap', ['--super-admin' => $initial->email])
            ->expectsOutput('Promoted: 0. Legacy admins granted permissions: 0.')->assertSuccessful();
        $this->assertSame([], $legacy->refresh()->permissions);
        $this->assertCount(1, app(AuthorizationAudit::class)->forUser($initial->id));
        $this->artisan('rbac:audit', ['--user' => $legacy->id])->expectsOutputToContain('bootstrap')->assertSuccessful();
        $this->get(route('admin.authorization.audit'))->assertOk()->assertSee($legacy->id);
        $this->assertTrue(Gate::forUser($initial)->allows('review-story-brief'));
        $this->assertFalse(Gate::forUser($legacy)->allows('review-story-brief'));
    }

    public function test_demo_seeder_creates_and_registers_the_super_admin_on_fresh_install(): void
    {
        $this->schema();
        app(UserSeeder::class)->run();
        $this->seed(UserSeeder::class);
        $super = User::where('email', 'admin@vietfeed.com')->sole();
        $this->assertTrue($super->isSuperAdmin());
        $this->assertSame((string) $super->id, app(SuperAdminGuard::class)->bootstrapUserId());
        $this->assertSame(3, User::where('role', 'user')->count());
        $this->actingAs($super)->deleteJson(route('admin.users.destroy', $super))->assertConflict();
    }

    public function test_audit_projection_failure_preserves_change_and_retries_without_duplicate_events(): void
    {
        $this->schema();
        $super = $this->superAdmin();
        $target = User::factory()->create();
        $client = app(CassandraClient::class);
        $keyspace = $client->keyspace();
        $this->assertSame('vietfeed_test', $keyspace);
        $client->execute('DROP TABLE '.$keyspace.'.'.AuthorizationAudit::BY_DAY);
        try {
            $this->actingAs($super)->patch(route('admin.users.update', $target), $this->profile($target, ['role' => 'admin', 'permissions' => [Permissions::STORIES]]))
                ->assertRedirect();
            $this->assertSame([Permissions::STORIES], $target->refresh()->permissions);
            $this->assertCount(1, $target->getAttribute(AuthorizationAudit::USER_OUTBOX));
            $this->get(route('admin.authorization.audit'))->assertOk()->assertSee('Nhật ký phân quyền hiện không khả dụng.');
            $this->artisan('rbac:audit')->expectsOutput('Authorization audit unavailable.')->assertFailed();
            $this->artisan('rbac:audit', ['action' => 'retry'])->expectsOutput('Delivered: 0.')->assertSuccessful();
            $queued = DB::connection('mongodb')->getCollection(AuthorizationAudit::OUTBOX)->findOne();
            $this->assertNotNull($queued);
            $this->assertNull($queued['attempts'] ?? null);
            $this->assertNull($queued['dead_at'] ?? null);
        } finally {
            app(AuthorizationAudit::class)->schema();
        }
        $audit = app(AuthorizationAudit::class);
        $emptyTarget = $audit->event('fixture', ' ', [], [], 'empty target');
        $future = $audit->event('fixture', $target, [], [], 'future timestamp');
        $future['at'] = now()->utc()->addYears(21)->format('Y-m-d\TH:i:s.v\Z');
        $audit->enqueue($emptyTarget);
        $audit->enqueue($future);
        $this->artisan('rbac:audit', ['action' => 'retry'])->expectsOutput('Delivered: 1.')->assertSuccessful();
        for ($attempt = 1; $attempt < AuthorizationAudit::MAX_ATTEMPTS; $attempt++) {
            $this->assertSame(0, $audit->retry());
        }
        $this->assertEqualsCanonicalizing([$emptyTarget['event_id'], $future['event_id']], array_column($audit->deadLetters(null), '_id'));
        $this->assertSame(1, DB::connection('mongodb')->getCollection('users')->countDocuments([
            '_id' => $target->getRawOriginal('id') ?? $target->getRawOriginal('_id'), 'permissions' => ['$type' => 'array'],
        ]));
        $events = app(AuthorizationAudit::class)->forUser($target->id);
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('7', ((string) $event['event_id'])[14]);
        $this->assertSame($super->id, $event['actor_id']);
        $this->assertSame(['role' => 'user', 'permissions' => []], json_decode($event['before_state'], true));
        $this->assertSame(['role' => 'admin', 'permissions' => [Permissions::STORIES]], json_decode($event['after_state'], true));
        $this->get(route('admin.authorization.audit'))->assertOk()->assertSee($super->name)->assertSee($target->name)
            ->assertSee('Vai trò: Người dùng → Quản trị viên')->assertSee('Quyền: — → stories.review')
            ->assertSee('table-responsive')->assertDontSee($event['after_state']);
        $target->delete();
        $this->get(route('admin.authorization.audit'))->assertOk()->assertSee('Người dùng đã xóa')->assertSee($target->id);
    }

    public function test_refusal_is_queued_when_cassandra_is_unavailable_and_route_gate_still_returns_403(): void
    {
        $this->schema();
        $admin = $this->admin([]);
        $staff = $this->superAdmin();
        $this->actingAs($admin);
        config(['nosql.cassandra.keyspace' => 'unavailable']);
        try {
            $this->deleteJson(route('admin.users.destroy', $staff))->assertForbidden();
        } finally {
            config(['nosql.cassandra.keyspace' => 'vietfeed_test']);
        }
        $event = DB::connection('mongodb')->getCollection(AuthorizationAudit::OUTBOX)->findOne();
        $this->assertSame('privilege_change_refused', $event['event']['reason']);
        $this->assertSame($admin->id, $event['event']['actor']);
        $this->assertSame($staff->id, $event['event']['target']);
        $this->assertTrue($staff->refresh()->isSuperAdmin());
        $this->artisan('rbac:audit', ['action' => 'retry'])->expectsOutput('Delivered: 1.')->assertSuccessful();
    }

    public function test_staff_bans_are_authorized_on_direct_standalone_report_and_appeal_paths(): void
    {
        $this->schema();
        $super = $this->superAdmin();
        $moderator = $this->admin([Permissions::MODERATION]);
        $reporter = User::factory()->create();
        $article = Article::create(['title' => 'Article', 'slug' => 'staff-ban', 'original_url' => 'https://example.test/staff-ban']);
        $service = app(ModerationService::class);
        $audit = app(AuthorizationAudit::class);
        foreach (['temporary_ban', 'permanent_ban'] as $type) {
            $target = $this->admin([]);
            $comment = Comment::create(['article_id' => $article->id, 'user_id' => $target->id, 'body' => 'Fixture']);
            $report = $service->reportComment($reporter, $comment, 'spam', null);
            try {
                $service->issueSanction($moderator, $target, $type, 'Fixture', 3);
                $this->fail('The service must refuse a ban of staff by an Admin.');
            } catch (ModerationException $e) {
                $this->assertStringContainsString('Chỉ Super Admin', $e->getMessage());
            }

            $payload = ['user_id' => $target->id, 'type' => $type, 'reason' => 'Fixture'];
            $this->actingAs($moderator)->post(route('admin.sanctions.store'), $payload)->assertSessionHas('error');
            $this->post(route('admin.sanctions.store'), $payload + ['report_id' => $report->id, 'hide_comment' => true])
                ->assertSessionHas('error');
            $this->assertSame('pending', $report->refresh()->status);
            $this->assertFalse((bool) $comment->refresh()->is_hidden);
            $this->assertSame(0, Sanction::where('user_id', $target->id)->count());

            $this->actingAs($super)->post(route('admin.sanctions.store'), array_replace($payload, ['user_id' => $super->id]))
                ->assertSessionHas('error');
            $this->post(route('admin.sanctions.store'), $payload + ['report_id' => $report->id])
                ->assertSessionHas('success');
            $this->assertSame('actioned', $report->refresh()->status);
            $sanction = Sanction::where('user_id', $target->id)->sole();
            $service->submitAppeal($target, $sanction, 'Please review this sanction.');
            $this->actingAs($moderator)->patch(route('admin.sanctions.update', $sanction), ['appeal_status' => 'denied'])
                ->assertSessionHas('error');
            $this->assertSame('pending', $sanction->refresh()->appeal_status);
            $this->patch(route('admin.sanctions.update', $sanction), ['appeal_status' => 'approved'])
                ->assertSessionHas('success');
            $this->assertFalse($sanction->refresh()->is_active);
            $events = $audit->forUser($target->id);
            $this->assertCount(4, $events);
            $this->assertSame(['staff_ban_refused'], array_values(array_unique(array_column($events, 'reason'))));
        }
        $this->assertSame(['super_admin_ban_refused'], array_values(array_unique(array_column($audit->forUser($super->id), 'reason'))));
        $this->assertSame('mute', $service->issueSanction($moderator, $super, 'mute', 'Fixture', 3)->type);
    }

    public function test_bootstrap_completion_cannot_reinsert_a_concurrently_demoted_super_admin(): void
    {
        $this->schema();
        $initial = $this->admin([]);
        $other = $this->superAdmin();
        $real = app(SuperAdminGuard::class);
        $authorization = app(UserAuthorization::class);
        $mock = $this->partialMock(SuperAdminGuard::class);
        $mock->shouldReceive('finishBootstrap')->once()->with((string) $initial->id)->andReturnUsing(function ($id) use ($real, $initial, $other, $authorization) {
            $this->assertEqualsCanonicalizing([$initial->id, $other->id], $real->memberIds());
            $authorization->edit($initial->fresh(), $other, [], Permissions::ADMIN, []);

            return $real->finishBootstrap($id);
        });
        $this->artisan('rbac:bootstrap', ['--super-admin' => $initial->email])->assertSuccessful();
        $this->assertSame([$initial->id], $real->memberIds());
        $this->assertSame([], $other->refresh()->permissions);
        $this->actingAs($initial->refresh())->deleteJson(route('admin.users.destroy', $initial))->assertConflict();
    }

    public function test_an_abandoned_bootstrap_candidate_can_be_cleared_without_changing_membership(): void
    {
        $this->schema();
        $abandoned = $this->admin([]);
        $replacement = $this->admin([]);
        $guard = app(SuperAdminGuard::class);
        $this->assertTrue($guard->beginBootstrap($abandoned->id));
        $this->assertTrue(app(UserAuthorization::class)->bootstrap($abandoned, Permissions::SUPER_ADMIN, []));

        $this->artisan('rbac:bootstrap', ['--super-admin' => $replacement->email])
            ->expectsOutput('Recorded candidate ID: '.$abandoned->id)->assertFailed();
        $this->artisan('rbac:bootstrap', ['--clear-candidate' => $replacement->id])->assertFailed();
        $this->artisan('rbac:bootstrap', ['--clear-candidate' => $abandoned->id])->assertFailed();
        $this->assertSame($abandoned->id, $guard->bootstrapCandidateId());
        $audit = app(AuthorizationAudit::class);
        $candidateEvents = fn () => collect($audit->forUser(SuperAdminGuard::ID))
            ->filter(fn ($event) => (json_decode($event['before_state'], true)['bootstrap_candidate_id'] ?? null) === $abandoned->id);
        $this->assertCount(0, $candidateEvents());

        $this->travel(61)->minutes();
        $this->artisan('rbac:bootstrap', ['--clear-candidate' => $abandoned->id])->assertSuccessful();
        $event = $candidateEvents()->sole();
        $this->assertSame('bootstrap_candidate_cleared', $event['reason']);
        $this->assertSame(['bootstrap_candidate_id' => null], json_decode($event['after_state'], true));
        $this->assertFalse($guard->finishBootstrap($abandoned->id));
        $this->assertSame([$abandoned->id], $guard->memberIds());
        $this->artisan('rbac:bootstrap', ['--super-admin' => $replacement->email])->assertSuccessful();
        $this->assertSame($replacement->id, $guard->bootstrapUserId());
        $this->assertEqualsCanonicalizing([$abandoned->id, $replacement->id], $guard->memberIds());

        // Even an exact match must fail when the completed marker exists.
        DB::connection('mongodb')->getCollection(SuperAdminGuard::COLLECTION)->updateOne(
            ['_id' => SuperAdminGuard::ID], ['$set' => ['bootstrap_candidate_id' => $abandoned->id]],
        );
        $this->artisan('rbac:bootstrap', ['--clear-candidate' => $abandoned->id])->assertFailed();
        $this->assertSame($abandoned->id, $guard->bootstrapCandidateId());
        $this->assertSame($replacement->id, $guard->bootstrapUserId());
    }

    public function test_repair_dry_run_and_apply_reconcile_guard_and_fence_in_flight_updates(): void
    {
        $this->schema();
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $obsolete = User::factory()->create();
        $guard = app(SuperAdminGuard::class);
        $generation = $guard->promotionGeneration();
        $guard->add($obsolete->id, $generation);
        $this->assertTrue($guard->reserveRemoval($second->id));
        $oldVersion = $second->getRawOriginal('authorization_version');

        $this->artisan('rbac:repair', ['--guard' => true])->expectsOutput('Dry run.')
            ->expectsOutput('Add members: '.$second->id)->expectsOutput('Remove members: '.$obsolete->id)->assertSuccessful();
        $this->assertEqualsCanonicalizing([$first->id, $obsolete->id], $guard->memberIds());
        config(['nosql.cassandra.keyspace' => 'unavailable']);
        try {
            $this->artisan('rbac:repair', ['--guard' => true, '--force' => true])->assertSuccessful();
        } finally {
            config(['nosql.cassandra.keyspace' => 'vietfeed_test']);
        }
        $control = DB::connection('mongodb')->getCollection(SuperAdminGuard::COLLECTION)->findOne(['_id' => SuperAdminGuard::ID]);
        $event = $control[AuthorizationAudit::USER_OUTBOX][0];
        $this->assertSame('super_admin_guard_repaired', $event['reason']);
        $this->assertSame(SuperAdminGuard::ID, $event['target']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $guard->memberIds());
        $matched = User::where('_id', $second->id)->where('authorization_version', $oldVersion)->update(['role' => 'user']);
        $this->assertSame(0, $matched);
        try {
            $guard->add($obsolete->id, $generation);
            $this->fail('An old promotion must not register after guard repair.');
        } catch (AuthorizationConflict) {
            $this->assertEqualsCanonicalizing([$first->id, $second->id], $guard->memberIds());
        }
        User::where('role', 'super_admin')->update(['role' => 'user']);
        $this->artisan('rbac:repair', ['--guard' => true, '--force' => true])
            ->expectsOutput('Guard repair requires at least one active Super Admin.')->assertFailed();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $guard->memberIds());
    }

    public function test_repair_clears_only_stale_markers_and_keeps_committed_sanctions(): void
    {
        $this->schema();
        $target = User::factory()->create();
        $admin = $this->superAdmin();
        $ban = Sanction::create(['user_id' => $target->id, 'admin_id' => $admin->id, 'type' => 'temporary_ban', 'is_active' => false, 'reason' => 'Fixture']);
        $users = DB::connection('mongodb')->getCollection('users');
        $filter = ['_id' => $target->getRawOriginal('id') ?? $target->getRawOriginal('_id')];
        $users->updateOne($filter, ['$set' => [
            'authorization_deleting' => true, 'authorization_deleting_at' => new UTCDateTime(now()),
            'authorization_ban_pending' => $ban->id, 'authorization_ban_pending_at' => new UTCDateTime(now()->subHours(2)),
        ]]);
        $this->artisan('rbac:repair', ['--user' => $target->id])->expectsOutput('Stale markers: authorization_ban_pending')->assertSuccessful();
        $this->assertSame($ban->id, $target->refresh()->authorization_ban_pending);
        $this->artisan('rbac:repair', ['--user' => $target->id, '--force' => true])->assertSuccessful();
        $this->assertNull($target->refresh()->authorization_ban_pending);
        $this->assertTrue($target->authorization_deleting);
        $this->assertNotNull($ban->fresh());

        $users->updateOne($filter, ['$set' => [
            'authorization_deleting_at' => new UTCDateTime(now()->subHours(2)),
            'authorization_ban_pending' => (string) Str::uuid7(), 'authorization_ban_pending_at' => new UTCDateTime(now()->subHours(2)),
        ]]);
        $this->artisan('rbac:repair', ['--user' => $target->id, '--force' => true])
            ->expectsOutput('Pause and drain writers before clearing an uncommitted ban marker.')->assertFailed();
        $this->assertTrue($target->refresh()->authorization_deleting);
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturn(true);
        $this->artisan('rbac:repair', ['--user' => $target->id, '--force' => true])->assertSuccessful();
        $this->assertNull($target->refresh()->authorization_deleting);
        $this->assertNull($target->authorization_ban_pending);
        $this->assertTrue($target->isUser());
        $reasons = array_column(app(AuthorizationAudit::class)->forUser($target->id), 'reason');
        $this->assertSame(['authorization_markers_repaired', 'authorization_markers_repaired'], $reasons);
    }

    public function test_demotion_with_all_permissions_still_revokes_tokens_and_redirects_self_to_home_when_plain(): void
    {
        $this->schema();
        $super = $this->superAdmin();
        $backup = $this->superAdmin();
        $tokens = $this->partialMock(ApiTokenService::class);
        $tokens->shouldReceive('cleanupForUser')->twice()->with((string) $super->id);
        $pair = $tokens->issue($super);
        $this->actingAs($super)->patch(route('admin.users.update', $super), $this->profile($super, [
            'role' => 'admin', 'permissions' => Permissions::ALL,
        ]))->assertRedirect(route('admin.dashboard'));
        $this->assertRevoked($pair);
        $this->actingAs($backup)->patch(route('admin.users.update', $super), $this->profile($super, ['role' => 'super_admin']))
            ->assertRedirect();
        $this->actingAs($super->refresh())->patch(route('admin.users.update', $super), $this->profile($super, ['role' => 'user']))
            ->assertRedirect(route('home'));
    }

    public function test_admin_and_self_service_email_changes_clear_verification_and_revoke_tokens(): void
    {
        $admin = $this->admin([Permissions::USERS]);
        $target = User::factory()->create();
        $oldEmail = $target->email;
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $tokens = $this->partialMock(ApiTokenService::class);
        $tokens->shouldReceive('cleanupForUser')->twice()->with((string) $target->id);
        $pair = $tokens->issue($target);
        $epoch = $target->api_token_epoch;
        $this->actingAs($admin)->patch(route('admin.users.update', $target), $this->profile($target, ['name' => 'Updated']))
            ->assertRedirect();
        $this->assertTrue($target->refresh()->hasVerifiedEmail());
        $this->assertSame($epoch, $target->api_token_epoch);
        $this->assertCount(0, $transport->messages());
        $this->patchJson(route('admin.users.update', $target), $this->profile($target, ['email' => 'Staff-Edit@example.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotNull($target->refresh()->getRawOriginal('remember_token'));
        $this->patch(route('admin.users.update', $target), $this->profile($target, ['email' => 'staff-edit@example.test']))
            ->assertRedirect();
        $this->assertNull($target->refresh()->email_verified_at);
        $this->assertNull($target->getRawOriginal('remember_token'));
        $this->assertRevoked($pair);
        $this->assertCount(1, $transport->messages());
        $notice = $transport->messages()->sole()->getOriginalMessage();
        $this->assertSame($oldEmail, $notice->getTo()[0]->getAddress());
        $this->assertSame('Email tài khoản VietFeed đã thay đổi', $notice->getSubject());

        Auth::logout();
        $unchanged = $target->getRawOriginal();
        Socialite::shouldReceive('driver->user')->once()->andReturn(
            (new SocialiteUser)->setRaw(['email_verified' => true])->map([
                'id' => 'google-changed-email', 'email' => $target->email, 'name' => 'Google User',
            ]),
        );
        $response = $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $session = $response->getCookie(config('session.cookie'));
        $this->withCookie($session->getName(), $session->getValue())->get(route('login'))
            ->assertOk()->assertSee('Hãy đăng nhập bằng mật khẩu và xác minh email của bạn.');
        $this->assertGuest();
        $this->assertNull($target->refresh()->google_id);
        $this->assertNull($target->email_verified_at);
        $this->post('/forgot-password', ['email' => $target->email])->assertSessionHas('status', __(Password::RESET_LINK_SENT));
        $this->assertFalse(Password::getRepository()->recentlyCreatedToken($target));
        $this->assertEquals($unchanged, $target->refresh()->getRawOriginal());
        $issued = Password::createToken($target);
        $this->post('/reset-password', [
            'email' => $target->email, 'token' => $issued, 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN)]);
        $this->assertEquals($unchanged, $target->refresh()->getRawOriginal());
        $this->assertCount(1, $transport->messages());

        $target->markEmailAsVerified();
        Password::deleteToken($target);
        $this->post('/forgot-password', ['email' => $target->email])->assertSessionHas('status', __(Password::RESET_LINK_SENT));
        $this->assertCount(2, $transport->messages());
        $this->assertSame($target->email, $transport->messages()->last()->getOriginalMessage()->getTo()[0]->getAddress());
        $oldEmail = $target->email;
        $target->forceFill(['remember_token' => Str::random(60)])->save();
        $pair = $tokens->issue($target);
        $this->actingAs($target)->patch(route('profile.update'), $this->profile($target, ['email' => 'self-edit@example.test']))
            ->assertRedirect(route('profile.edit'))->assertSessionHas('status', 'profile-updated');
        $this->assertNull($target->refresh()->email_verified_at);
        $this->assertNull($target->getRawOriginal('remember_token'));
        $this->assertRevoked($pair);
        Auth::logout();
        $this->post('/forgot-password', ['email' => $target->email])->assertSessionHas('status', __(Password::RESET_LINK_SENT));
        $this->assertCount(3, $transport->messages());
        $selfNotice = $transport->messages()->last()->getOriginalMessage();
        $this->assertSame($oldEmail, $selfNotice->getTo()[0]->getAddress());
        $this->assertSame($notice->getSubject(), $selfNotice->getSubject());
    }

    public function test_user_editor_restores_selected_and_all_cleared_grants_after_validation(): void
    {
        $super = $this->superAdmin();
        $target = $this->admin([Permissions::USERS]);
        $edit = route('admin.users.edit', $target);
        $this->actingAs($super);
        foreach ([[Permissions::CONTENT], []] as $selected) {
            $input = ['role' => 'admin', 'permissions_form' => '1', 'name' => ''];
            if ($selected !== []) {
                $input['permissions'] = $selected;
            }
            $this->from($edit)->patch(route('admin.users.update', $target), $this->profile($target, $input))
                ->assertRedirect($edit)->assertSessionHasErrors('name');
            $dom = new DOMDocument;
            $dom->loadHTML($this->get($edit)->assertOk()->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $checked = [];
            foreach ((new DOMXPath($dom))->query('//input[@name="permissions[]" and @checked]') as $checkbox) {
                $checked[] = $checkbox->getAttribute('value');
            }
            $this->assertEqualsCanonicalizing($selected, $checked);
        }
        $this->assertSame([Permissions::USERS], $target->refresh()->permissions);
    }

    public function test_connection_failure_stops_retry_and_preserves_attempts_for_the_whole_batch(): void
    {
        $this->schema();
        $target = User::factory()->create();
        $audit = app(AuthorizationAudit::class);
        $healthy = $audit->event('fixture', $target, [], [], 'healthy');
        $idPrefix = substr($healthy['event_id'], 0, -1);
        $healthy['event_id'] = $idPrefix.'1';
        $poison = $healthy;
        $poison['event_id'] = $idPrefix.'2';
        $poison['at'] = 'not a timestamp';
        $audit->enqueue($healthy);
        $audit->enqueue($poison);
        $outbox = DB::connection('mongodb')->getCollection(AuthorizationAudit::OUTBOX);
        $attempts = AuthorizationAudit::MAX_ATTEMPTS - 1;
        $outbox->updateMany(['_id' => ['$in' => [$healthy['event_id'], $poison['event_id']]]], ['$set' => ['attempts' => $attempts]]);

        $endpoint = config('nosql.cassandra');
        config(['nosql.cassandra.host' => '127.0.0.1', 'nosql.cassandra.port' => 1]);
        try {
            for ($i = 0; $i < AuthorizationAudit::MAX_ATTEMPTS + 1; $i++) {
                $this->assertSame(0, $audit->retry());
            }
        } finally {
            config(['nosql.cassandra' => $endpoint]);
        }
        foreach ([$healthy, $poison] as $event) {
            $pending = $outbox->findOne(['_id' => $event['event_id']]);
            $this->assertSame($attempts, $pending['attempts']);
            $this->assertNull($pending['dead_at'] ?? null);
        }
        $this->assertSame(1, $audit->retry());
        $this->assertNull($outbox->findOne(['_id' => $healthy['event_id']]));
        $this->assertSame([$poison['event_id']], array_column($audit->deadLetters($target->id), '_id'));
        $this->assertSame(['healthy'], array_column($audit->forUser($target->id), 'reason'));
    }

    public function test_dead_letters_are_paginated_and_bulk_replay_only_requeues_a_bounded_batch(): void
    {
        $audit = app(AuthorizationAudit::class);
        $target = User::factory()->create();
        $outbox = DB::connection('mongodb')->getCollection(AuthorizationAudit::OUTBOX);
        $recordedAt = now();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $event = $audit->event('fixture', $target, [], [], 'dead fixture');
            $ids[] = $event['event_id'];
            $outbox->insertOne([
                '_id' => $event['event_id'], 'event' => $event,
                'attempts' => AuthorizationAudit::MAX_ATTEMPTS,
                'dead_at' => new UTCDateTime($recordedAt->copy()->subMinutes($i)),
            ]);
        }

        $pending = $audit->event('fixture', $target, [], [], 'pending fixture');
        $outbox->insertOne(['_id' => $pending['event_id'], 'event' => $pending, 'attempts' => 2, 'dead_at' => null]);

        $this->artisan('rbac:audit', ['action' => 'dead', '--limit' => 2])
            ->expectsOutputToContain($ids[0])->expectsOutputToContain($ids[1])->doesntExpectOutputToContain($ids[2])->assertSuccessful();
        $this->artisan('rbac:audit', ['action' => 'dead', '--limit' => 2, '--page' => 2])
            ->expectsOutputToContain($ids[2])->doesntExpectOutputToContain($ids[0])->assertSuccessful();

        $this->artisan('rbac:audit', ['action' => 'replay', '--all' => true, '--limit' => 2])
            ->expectsOutput('Requeued: 2.')->assertSuccessful();
        $this->assertSame([$ids[0]], array_column($audit->deadLetters(null), '_id'));
        foreach ([$ids[1], $ids[2]] as $id) {
            $entry = $outbox->findOne(['_id' => $id]);
            $this->assertNull($entry['dead_at'] ?? null);
            $this->assertNull($entry['attempts'] ?? null);
        }
        $this->artisan('rbac:audit', ['action' => 'replay', '--all' => true])->expectsOutput('Requeued: 1.')->assertSuccessful();
        $this->artisan('rbac:audit', ['action' => 'replay', '--all' => true])->expectsOutput('Requeued: 0.')->assertSuccessful();
        $this->assertSame(4, $outbox->countDocuments(['dead_at' => null]));
        $this->assertSame(2, $outbox->findOne(['_id' => $pending['event_id']])['attempts']);
    }

    public function test_poison_audit_event_does_not_block_the_batch_and_can_be_inspected_and_replayed(): void
    {
        $this->schema();
        $target = User::factory()->create();
        $audit = app(AuthorizationAudit::class);
        $poison = $audit->event('fixture', $target, [], [], 'poison');
        $broken = $poison;
        unset($broken['at']);
        DB::connection('mongodb')->getCollection('users')->updateOne(
            ['_id' => $target->getRawOriginal('id') ?? $target->getRawOriginal('_id')],
            ['$push' => [AuthorizationAudit::USER_OUTBOX => $broken]],
        );
        $healthy = $audit->event('fixture', $target, [], [], 'healthy');
        $audit->enqueue($healthy);

        $this->artisan('rbac:audit', ['action' => 'retry'])->expectsOutput('Delivered: 1.')->assertSuccessful();
        $this->assertContains('healthy', array_column($audit->forUser($target->id), 'reason'));
        for ($attempt = 1; $attempt < AuthorizationAudit::MAX_ATTEMPTS; $attempt++) {
            $this->assertSame(0, $audit->retry());
        }
        $this->artisan('rbac:audit', ['action' => 'dead', '--user' => $target->id])
            ->expectsOutputToContain($poison['event_id'])->assertSuccessful();
        $dead = $audit->deadLetters($target->id);
        $this->assertCount(1, $dead);
        $this->assertSame(AuthorizationAudit::MAX_ATTEMPTS, $dead[0]['attempts']);
        DB::connection('mongodb')->getCollection(AuthorizationAudit::OUTBOX)->updateOne(
            ['_id' => $poison['event_id']], ['$set' => ['event' => $poison]],
        );
        $this->artisan('rbac:audit', ['action' => 'replay', '--event' => $poison['event_id']])->assertSuccessful();
        $this->assertSame(1, $audit->retry());
        $this->assertSame([], $audit->deadLetters($target->id));
        $this->assertEqualsCanonicalizing(['poison', 'healthy'], array_column($audit->forUser($target->id), 'reason'));
    }

    private function schema(): void
    {
        $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
        $this->artisan('rbac:audit', ['action' => 'schema'])->assertSuccessful();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        app(SuperAdminGuard::class)->add((string) $user->id);

        return $user;
    }

    private function admin(array $permissions): User
    {
        return User::factory()->create(['role' => 'admin', 'permissions' => $permissions]);
    }

    private function profile(User $user, array $fields = []): array
    {
        return $fields + ['name' => $user->name, 'email' => $user->email];
    }

    private function resumeWebSession(User $user): self
    {
        $this->withSession([Auth::guard('web')->getName() => $user->id]);
        Auth::forgetGuards();

        return $this;
    }

    private function assertRevoked(array $pair): void
    {
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$pair['access_token']])->assertUnauthorized()
            ->assertJsonPath('error.code', 'token_family_revoked');
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertUnauthorized()
            ->assertJsonPath('error.code', 'token_family_revoked');
    }
}

class AdminActionProbe
{
    public function handle(Request $request, Closure $next)
    {
        return response()->noContent();
    }
}
