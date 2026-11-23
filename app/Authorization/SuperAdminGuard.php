<?php

namespace App\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

class SuperAdminGuard
{
    public const COLLECTION = 'authorization_control';

    public const ID = 'super-admins';

    public function promotionGeneration(): ?string
    {
        $guard = $this->snapshot();
        if (isset($guard['repair_token'])) {
            throw new AuthorizationConflict('Đang đối soát danh sách Super Admin. Vui lòng thử lại.');
        }

        return $guard['generation'] ?? null;
    }

    public function add(string $userId, ?string $generation = null): void
    {
        $this->initialize();
        $result = $this->collection()->updateOne(
            ['_id' => self::ID, 'repair_token' => null, 'generation' => $generation],
            ['$addToSet' => ['user_ids' => $userId]],
        );
        if ($result->getMatchedCount() !== 1) {
            throw new AuthorizationConflict('Danh sách Super Admin vừa thay đổi. Vui lòng kiểm tra lại tài khoản.');
        }
    }

    public function reserveRemoval(string $userId): bool
    {
        return $this->collection()->updateOne(
            ['_id' => self::ID, 'repair_token' => null, 'user_ids' => $userId, 'user_ids.1' => ['$exists' => true]],
            ['$pull' => ['user_ids' => $userId]],
        )->getModifiedCount() === 1;
    }

    public function isLastMember(string $userId): bool
    {
        $guard = $this->snapshot();

        return ! isset($guard['repair_token']) && (array) ($guard['user_ids'] ?? []) === [$userId];
    }

    public function bootstrapUserId(): ?string
    {
        return $this->snapshot()['bootstrap_user_id'] ?? null;
    }

    public function bootstrapCandidateId(): ?string
    {
        return $this->snapshot()['bootstrap_candidate_id'] ?? null;
    }

    public function clearBootstrapCandidate(string $expectedId, array $event): bool
    {
        $filter = [
            '_id' => self::ID, 'bootstrap_user_id' => null,
            'bootstrap_candidate_id' => $expectedId, 'repair_token' => null,
        ];
        $collection = $this->collection();
        // Give pre-upgrade markers with no timestamp a full staleness window.
        $collection->updateOne($filter + ['bootstrap_started_at' => null], [
            '$set' => ['bootstrap_started_at' => new UTCDateTime(now())],
        ]);

        return $collection->updateOne($filter + [
            'bootstrap_started_at' => ['$lt' => new UTCDateTime(now()->subMinutes(AuthorizationRepair::MINIMUM_AGE_MINUTES))],
        ], [
            '$unset' => ['bootstrap_candidate_id' => '', 'bootstrap_started_at' => ''],
            '$push' => [AuthorizationAudit::USER_OUTBOX => $event],
        ])->getModifiedCount() === 1;
    }

    public function beginBootstrap(string $userId): bool
    {
        $this->initialize();

        return $this->collection()->updateOne([
            '_id' => self::ID, 'bootstrap_user_id' => null, 'repair_token' => null,
            '$or' => [['bootstrap_candidate_id' => null], ['bootstrap_candidate_id' => $userId]],
        ], ['$set' => ['bootstrap_candidate_id' => $userId, 'bootstrap_started_at' => new UTCDateTime(now())]])->getMatchedCount() === 1;
    }

    public function finishBootstrap(string $userId): bool
    {
        // Promotions and removals maintain membership. Bootstrap only commits its marker.
        return $this->collection()->updateOne([
            '_id' => self::ID, 'bootstrap_user_id' => null, 'bootstrap_candidate_id' => $userId,
            'repair_token' => null, 'user_ids.0' => ['$exists' => true],
        ], [
            '$set' => ['bootstrap_user_id' => $userId],
            '$unset' => ['bootstrap_candidate_id' => '', 'bootstrap_started_at' => ''],
        ])->getMatchedCount() === 1;
    }

    public function memberIds(): array
    {
        return (array) ($this->snapshot()['user_ids'] ?? []);
    }

    public function beginRepair(?UTCDateTime $staleBefore = null): ?string
    {
        $this->initialize();
        $token = (string) Str::uuid7();
        $available = [['repair_token' => null]];
        if ($staleBefore !== null) {
            $available[] = ['repair_started_at' => ['$lt' => $staleBefore]];
        }
        $result = $this->collection()->updateOne(
            ['_id' => self::ID, '$or' => $available],
            ['$set' => ['repair_token' => $token, 'repair_started_at' => new UTCDateTime(now()), 'generation' => $token]],
        );

        return $result->getMatchedCount() === 1 ? $token : null;
    }

    public function finishRepair(string $token, array $userIds, array $event): void
    {
        if ($userIds === [] || $this->collection()->updateOne(
            ['_id' => self::ID, 'repair_token' => $token],
            [
                '$set' => ['user_ids' => array_values($userIds)],
                '$unset' => ['repair_token' => '', 'repair_started_at' => ''],
                '$push' => [AuthorizationAudit::USER_OUTBOX => $event],
            ],
        )->getMatchedCount() !== 1) {
            throw new \RuntimeException('Unable to reconcile Super Admin membership.');
        }
    }

    public function releaseRepair(string $token): void
    {
        $this->collection()->updateOne(['_id' => self::ID, 'repair_token' => $token], ['$unset' => ['repair_token' => '', 'repair_started_at' => '']]);
    }

    private function initialize(): void
    {
        $this->collection()->updateOne(['_id' => self::ID], ['$setOnInsert' => ['user_ids' => []]], ['upsert' => true]);
    }

    private function snapshot(): array
    {
        return (array) $this->collection()->findOne(['_id' => self::ID]);
    }

    private function collection(): Collection
    {
        return DB::connection('mongodb')->getCollection(self::COLLECTION);
    }
}
