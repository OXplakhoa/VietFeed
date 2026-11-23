<?php

namespace App\Authorization;

use App\Models\Sanction;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use RuntimeException;

final class AuthorizationRepair
{
    public const MINIMUM_AGE_MINUTES = 60;

    private const MARKERS = ['authorization_deleting', 'authorization_ban_pending'];

    public function __construct(
        private SuperAdminGuard $guard,
        private UserAuthorization $authorization,
        private AuthorizationAudit $audit,
    ) {}

    public function user(string $id, bool $force, bool $writersPaused): array
    {
        $user = User::find($id) ?? throw new RuntimeException('User not found.');
        $cutoff = $this->cutoff();
        $markers = [];
        $unset = [];
        foreach (self::MARKERS as $marker) {
            $value = $user->getRawOriginal($marker);
            if (! $value || ! $this->isStale($user, $marker, $cutoff)) {
                continue;
            }
            if ($force && ! $writersPaused && $marker === 'authorization_ban_pending'
                && ! $this->banCommitted($id, $value)) {
                throw new RuntimeException('Pause and drain writers before clearing an uncommitted ban marker.');
            }
            $markers[] = $marker;
            $unset[$marker] = '';
            $unset[$marker.'_at'] = '';
        }

        if (! $force || $markers === []) {
            return $markers;
        }

        $before = ['role' => $user->role, 'permissions' => $user->permissions ?? [], 'markers' => $markers];
        $after = $before;
        $after['markers'] = [];
        $event = $this->audit->event('rbac:repair', $user, $before, $after, 'authorization_markers_repaired');
        $filter = $this->snapshot($user);
        foreach ($markers as $marker) {
            $filter[$marker] = $user->getRawOriginal($marker);
        }
        if ($this->users()->updateOne($filter, [
            '$set' => ['authorization_version' => $event['event_id']],
            '$unset' => $unset,
            '$push' => [AuthorizationAudit::USER_OUTBOX => $event],
        ])->getModifiedCount() !== 1) {
            throw new RuntimeException('User authorization changed during repair. Recheck the dry run.');
        }
        $this->audit->deliver($event, $user);

        return $markers;
    }

    public function guard(bool $force, bool $writersPaused): array
    {
        $token = $force ? $this->guard->beginRepair($writersPaused ? new UTCDateTime($this->cutoff()) : null) : null;
        if ($force && $token === null) {
            throw new RuntimeException('A guard repair is already in progress.');
        }
        try {
            $before = $this->guard->memberIds();
            $eligible = [];
            foreach (User::where('role', Permissions::SUPER_ADMIN)->cursor() as $user) {
                if (! $this->authorization->canBecomeSuperAdmin($user)) {
                    continue;
                }
                // Fence a removal that reserved an absent member before repair took ownership.
                if ($force && ! in_array((string) $user->id, $before, true) && $this->users()->updateOne(
                    $this->snapshot($user) + ['authorization_ban_pending' => null],
                    ['$set' => ['authorization_version' => (string) Str::uuid7()]],
                )->getMatchedCount() !== 1) {
                    throw new RuntimeException('A Super Admin changed during repair. Recheck the dry run.');
                }
                $eligible[] = (string) $user->id;
            }
            if ($eligible === []) {
                throw new RuntimeException('Guard repair requires at least one active Super Admin.');
            }

            $added = array_values(array_diff($eligible, $before));
            $removed = array_values(array_diff($before, $eligible));
            if ($token !== null && ($added !== [] || $removed !== [])) {
                sort($before);
                sort($eligible);
                $event = $this->audit->event('rbac:repair', SuperAdminGuard::ID,
                    ['super_admin_ids' => $before], ['super_admin_ids' => $eligible], 'super_admin_guard_repaired');
                $this->guard->finishRepair($token, $eligible, $event);
                $this->audit->deliverGuard($event);
            }

            return ['added' => $added, 'removed' => $removed];
        } finally {
            if ($token !== null) {
                $this->guard->releaseRepair($token);
            }
        }
    }

    private function isStale(User $user, string $marker, DateTimeInterface $cutoff): bool
    {
        $timestamp = $user->getRawOriginal($marker.'_at');
        if ($timestamp instanceof UTCDateTime) {
            $timestamp = $timestamp->toDateTime();
        }
        if ($timestamp instanceof DateTimeInterface) {
            return $timestamp < $cutoff;
        }

        // Pre-review markers use the UUIDv7 of the deletion event or sanction.
        $id = $user->getRawOriginal($marker === 'authorization_deleting' ? 'authorization_version' : $marker);

        return is_string($id) && Str::isUuid($id) && $id[14] === '7'
            && hexdec(substr(str_replace('-', '', $id), 0, 12)) < $cutoff->getTimestamp() * 1000;
    }

    private function banCommitted(string $id, string $sanction): bool
    {
        return Sanction::where('_id', $sanction)->where('user_id', $id)
            ->whereIn('type', ['temporary_ban', 'permanent_ban'])->exists();
    }

    private function cutoff(): DateTimeInterface
    {
        return now()->subMinutes(self::MINIMUM_AGE_MINUTES);
    }

    private function snapshot(User $user): array
    {
        return [
            '_id' => $user->getRawOriginal('id') ?? $user->getRawOriginal('_id'),
            'role' => $user->getRawOriginal('role'),
            'authorization_version' => $user->getRawOriginal('authorization_version'),
        ];
    }

    private function users(): Collection
    {
        return DB::connection('mongodb')->getCollection('users');
    }
}
