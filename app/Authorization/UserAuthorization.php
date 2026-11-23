<?php

namespace App\Authorization;

use App\ApiTokens\ApiTokenService;
use App\Models\Sanction;
use App\Models\User;
use App\Moderation\ModerationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Throwable;

final class UserAuthorization
{
    public function __construct(
        private SuperAdminGuard $guard,
        private AuthorizationAudit $audit,
        private ApiTokenService $tokens,
    ) {}

    public function edit(User $actor, User $target, array $profile, ?string $role, array $permissions): void
    {
        $this->authorizeManagement($actor, $target, $role !== null);
        $this->write($target, (string) $actor->id, $profile, $role ?? $target->role, $role === null ? $target->permissions ?? [] : $permissions, 'admin_update');
    }

    public function updateProfile(User $user, array $profile): void
    {
        $this->write($user, (string) $user->id, $profile, $user->role, $user->permissions ?? [], 'profile_update');
    }

    public function authorizeManagement(User $actor, User $target, bool $changesAuthorization = false): void
    {
        try {
            Gate::forUser($actor)->authorize(Permissions::MANAGE_USER, $target);
            if ($changesAuthorization) {
                Gate::forUser($actor)->authorize(Permissions::SUPER);
            }
        } catch (AuthorizationException $denial) {
            $this->recordPrivilegeRefusal($actor, $target);

            throw $denial;
        }
    }

    public function recordPrivilegeRefusal(User $actor, User $target): void
    {
        $this->recordRefusal((string) $actor->id, $target, 'privilege_change_refused');
    }

    public function bootstrap(User $target, string $role, array $permissions): bool
    {
        return $this->write($target, 'rbac:bootstrap', [], $role, $permissions, 'bootstrap');
    }

    public function provisionModerationDemoAdmin(User $target): void
    {
        if ($target->isSuperAdmin() || $target->getRawOriginal('authorization_version') !== null) {
            return;
        }

        $permissions = [
            Permissions::MODERATION, Permissions::COMMENTS, Permissions::USERS, Permissions::OPERATIONS,
            ...($target->permissions ?? []),
        ];
        $this->write($target, 'db:seed:ModerationDemoSeeder', [], Permissions::ADMIN, $permissions, 'demo_seed');
    }

    public function delete(User $actor, User $target, bool $selfService = false): void
    {
        if ($selfService) {
            abort_unless($actor->is($target), 403);
        } else {
            $this->authorizeManagement($actor, $target);
        }

        $filter = $this->snapshot($target);
        $event = $target->isAdmin() ? $this->audit->event((string) $actor->id, $target, $this->state($target->role, $target->permissions), null, 'account_deleted') : null;
        if ($target->isSuperAdmin()) {
            $this->reserveRemoval($target, (string) $actor->id);
        }

        try {
            // Keep undelivered authorization history after the user document is removed.
            if ($event !== null) {
                $this->tokens->revokeAllForUser((string) $target->id, fn () => $this->update($filter, [
                    '$set' => [
                        'role' => Permissions::USER,
                        'permissions' => [],
                        'authorization_version' => $event['event_id'],
                        'authorization_deleting' => true,
                        'authorization_deleting_at' => new UTCDateTime(now()),
                    ],
                    '$inc' => ['api_token_epoch' => 1],
                    '$push' => [AuthorizationAudit::USER_OUTBOX => $event],
                ]));
                $filter['role'] = Permissions::USER;
                $filter['authorization_version'] = $event['event_id'];
            }
            $this->audit->transferPending($target);
            if ($this->users()->deleteOne($filter)->getDeletedCount() !== 1) {
                throw new AuthorizationConflict;
            }
        } catch (Throwable $e) {
            $this->logGuardFailure($target, $e);
            throw $e;
        }

        $this->tokens->cleanupForUser((string) $target->id);
        if ($event !== null) {
            $this->audit->deliver($event);
        }
    }

    public function authorizeBan(User $actor, User $target, string $type): void
    {
        if (! in_array($type, ['temporary_ban', 'permanent_ban'], true) || ! $target->isAdmin()) {
            return;
        }
        if ($target->isSuperAdmin()) {
            $this->recordRefusal((string) $actor->id, $target, 'super_admin_ban_refused');
            throw new ModerationException('Phải thay đổi vai trò Super Admin trước khi áp dụng lệnh cấm.');
        }
        if (! $actor->isSuperAdmin()) {
            $this->recordRefusal((string) $actor->id, $target, 'staff_ban_refused');
            throw new ModerationException('Chỉ Super Admin mới có thể áp dụng hoặc giữ lệnh cấm đối với quản trị viên.');
        }
    }

    /** Fence staff promotion while a ban is being written to the sanctions collection. */
    public function beginBan(User $actor, string $targetId, string $sanctionId, string $type): void
    {
        $target = User::findOrFail($targetId);
        $this->authorizeBan($actor->fresh() ?? throw new ModerationException('Không tìm thấy người thực hiện.'), $target, $type);
        if ($this->users()->updateOne($this->snapshot($target) + ['authorization_ban_pending' => null], [
            '$set' => [
                'authorization_ban_pending' => $sanctionId,
                'authorization_ban_pending_at' => new UTCDateTime(now()),
                'authorization_version' => (string) Str::uuid7(),
            ],
        ])->getMatchedCount() !== 1) {
            throw new ModerationException('Quyền của tài khoản vừa thay đổi. Vui lòng thử lại.');
        }
    }

    public function finishBan(string $targetId, string $sanctionId): void
    {
        User::where('_id', $targetId)->where('authorization_ban_pending', $sanctionId)->toBase()
            ->drop(['authorization_ban_pending', 'authorization_ban_pending_at']);
    }

    public function canBecomeSuperAdmin(User $target): bool
    {
        return $target->getAttribute('authorization_ban_pending') === null
            && ! Sanction::where('user_id', (string) $target->id)
                ->whereIn('type', ['temporary_ban', 'permanent_ban'])->active()->exists();
    }

    private function recordRefusal(string $actor, User $target, string $reason): void
    {
        $state = $this->state($target->role, $target->permissions);
        $this->audit->recordBestEffort($this->audit->event($actor, $target, $state, $state, $reason));
    }

    private function write(User $target, string $actor, array $profile, string $role, array $permissions, string $reason): bool
    {
        $before = $this->state($target->role, $target->permissions);
        $after = $this->state($role, $permissions);
        $filter = $this->snapshot($target);
        $set = $profile + ['updated_at' => new UTCDateTime(now())];
        $emailChanged = array_key_exists('email', $profile) && $profile['email'] !== $target->email;
        if (array_key_exists('email', $profile)) {
            $filter['email'] = $target->email;
        }
        if ($emailChanged) {
            $set['email_verified_at'] = null;
        }
        $roleReduced = array_search($role, Permissions::ROLES, true) < array_search($target->role, Permissions::ROLES, true);
        $revoke = $emailChanged || $roleReduced || array_diff($this->effective($before), $this->effective($after)) !== [];
        if ($before === $after) {
            if ($emailChanged) {
                $this->tokens->revokeAllForUser((string) $target->id, fn () => $this->update($filter, ['$set' => $set, '$inc' => ['api_token_epoch' => 1]]));
            } elseif ($profile !== []) {
                $this->update($filter, ['$set' => $set]);
            }

            return false;
        }

        $promoting = ! $target->isSuperAdmin() && $role === Permissions::SUPER_ADMIN;
        $removing = $target->isSuperAdmin() && $role !== Permissions::SUPER_ADMIN;
        if ($target->getAttribute('authorization_deleting')) {
            throw new AuthorizationConflict('Tài khoản đang được xóa.');
        }
        if (($promoting && ! $this->canBecomeSuperAdmin($target))
            || ($target->isUser() && $role === Permissions::ADMIN && $target->getAttribute('authorization_ban_pending') !== null)) {
            $this->recordRefusal($actor, $target, 'staff_promotion_refused');
            throw new AuthorizationConflict('Tài khoản đang bị cấm hoặc đang được xử lý.');
        }
        $generation = $promoting ? $this->guard->promotionGeneration() : null;
        if ($removing) {
            $this->reserveRemoval($target, $actor);
        }

        $event = $this->audit->event($actor, $target, $before, $after, $reason);
        $change = [
            '$set' => $set + $after + ['authorization_version' => $event['event_id']],
            '$push' => [AuthorizationAudit::USER_OUTBOX => $event],
        ];
        try {
            if ($revoke) {
                $this->tokens->revokeAllForUser((string) $target->id, fn () => $this->update($filter, $change + ['$inc' => ['api_token_epoch' => 1]]));
            } else {
                $this->update($filter, $change);
            }
            if ($promoting) {
                // A super admin without a guard entry cannot be removed. Register only after promotion has committed.
                $this->guard->add((string) $target->id, $generation);
            }
        } catch (Throwable $e) {
            if ($promoting || $removing) {
                $this->logGuardFailure($target, $e, $promoting);
            }
            throw $e;
        }

        $this->audit->deliver($event, $target);

        return true;
    }

    private function reserveRemoval(User $target, string $actor): void
    {
        if (! $this->guard->reserveRemoval((string) $target->id)) {
            $this->recordRefusal($actor, $target, 'super_admin_removal_refused');
            throw new AuthorizationConflict('Phải giữ ít nhất một Super Admin. Vui lòng thử lại nếu tài khoản vừa thay đổi.');
        }
    }

    private function snapshot(User $target): array
    {
        return [
            '_id' => $target->getRawOriginal('id') ?? $target->getRawOriginal('_id'),
            'role' => $target->getRawOriginal('role'),
            'authorization_version' => $target->getRawOriginal('authorization_version'),
        ];
    }

    private function update(array $filter, array $update): void
    {
        if ($this->users()->updateOne($filter, $update)->getMatchedCount() !== 1) {
            throw new AuthorizationConflict;
        }
    }

    private function state(string $role, ?array $permissions): array
    {
        return ['role' => $role, 'permissions' => $role === Permissions::ADMIN ? array_values(array_intersect(Permissions::ALL, $permissions ?? [])) : []];
    }

    private function effective(array $state): array
    {
        return $state['role'] === Permissions::SUPER_ADMIN ? Permissions::ALL : $state['permissions'];
    }

    private function logGuardFailure(User $target, Throwable $error, bool $promoting = false): void
    {
        if ($promoting || $target->isSuperAdmin()) {
            Log::error('Check Super Admin guard after interrupted membership change', ['target_id' => (string) $target->id, 'exception_type' => $error::class]);
        }
    }

    private function users(): Collection
    {
        return DB::connection('mongodb')->getCollection('users');
    }
}
