<?php

namespace App\ReadingPass;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;

/**
 * MongoDB `article_unlocks`: the durable Reading Pass record (one document per subject and article).
 *
 * A request reserves its unlock (`pending`) before Redis decides, so a quota rebuild that races an in-flight grant
 * still counts it; the reservation then becomes `granted` or is deleted. `fallback: true` marks documents written
 * while Redis was unavailable: the next decision for that subject rebuilds its window first.
 */
final class UnlockLedger
{
    public const COLLECTION = 'article_unlocks';

    public const PENDING = 'pending';

    public const GRANTED = 'granted';

    public const USER_PREFIX = 'u:';

    public const GUEST_PREFIX = 's:';

    public static function userSubject(string $userId): string
    {
        return self::USER_PREFIX.$userId;
    }

    /** Raw session ids are bearer secrets: only their SHA-256 is persisted. */
    public static function sessionHash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public static function guestSubject(string $sessionId): string
    {
        return self::GUEST_PREFIX.self::sessionHash($sessionId);
    }

    public static function ms(CarbonInterface $time): int
    {
        return (int) $time->getPreciseTimestamp(3);
    }

    public static function date(CarbonInterface $time): UTCDateTime
    {
        return new UTCDateTime(self::ms($time));
    }

    public static function msOf(UTCDateTime $date): int
    {
        return (int) (string) $date;
    }

    /**
     * The document for this article, and whether the subject has documents written during a Redis outage.
     *
     * @return array{doc: ?array, fallback: bool}
     */
    public function lookup(string $subject, string $articleId): array
    {
        $doc = null;
        $fallback = false;
        $cursor = $this->collection()->find(
            ['subject' => $subject, '$or' => [['article_id' => $articleId], ['fallback' => true]]],
            ['projection' => ['article_id' => 1, 'state' => 1, 'unlocked_at' => 1, 'created_at' => 1, 'fallback' => 1]],
        );
        foreach ($cursor as $row) {
            if ($row['article_id'] === $articleId) {
                $doc = $row;
            }
            $fallback = $fallback || ($row['fallback'] ?? false) === true;
        }

        return ['doc' => $doc, 'fallback' => $fallback];
    }

    /** Insert a pending reservation; null when the subject already has a document for the article. */
    public function reserve(array $fields, CarbonInterface $now): ?string
    {
        $id = $fields['_id'] ?? (string) Str::uuid7();
        $writtenAt = self::writeTime($fields, $now);
        try {
            $this->collection()->insertOne($fields + [
                '_id' => $id,
                'state' => self::PENDING,
                'unlocked_at' => self::date($now),
                'created_at' => $writtenAt,
                'updated_at' => $writtenAt,
            ]);
        } catch (BulkWriteException $e) {
            if (self::isDuplicate($e)) {
                return null;
            }
            throw $e;
        }

        return $id;
    }

    /**
     * Turn the reservation into a granted unlock; re-create it when a sweeper removed it as abandoned.
     *
     * @return array the granted document
     */
    public function grant(string $id, array $fields, CarbonInterface $now, string $tier, bool $fallback): array
    {
        $writtenAt = self::writeTime($fields, $now);
        $set = ['state' => self::GRANTED, 'tier' => $tier, 'updated_at' => $writtenAt] + ($fallback ? ['fallback' => true] : []);
        $doc = $this->collection()->findOneAndUpdate(['_id' => $id], ['$set' => $set], ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
        if ($doc !== null) {
            return $doc;
        }

        return $this->collection()->findOneAndUpdate(
            ['subject' => $fields['subject'], 'article_id' => $fields['article_id']],
            ['$set' => $set, '$setOnInsert' => $fields + ['_id' => $id, 'unlocked_at' => self::date($now), 'created_at' => $writtenAt]],
            ['upsert' => true, 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
    }

    private static function writeTime(array $fields, CarbonInterface $eventTime): UTCDateTime
    {
        return self::date(($fields['synthetic'] ?? false) ? CarbonImmutable::now() : $eventTime);
    }

    public function release(string $id): void
    {
        $this->collection()->deleteOne(['_id' => $id, 'state' => self::PENDING]);
    }

    /**
     * Documents that make up the subject's window: granted since $since, pending reservations newer than
     * $staleBefore, and every document flagged `fallback` (returned so a rebuild can clear the flags it covered).
     *
     * @return array{members: list<array{0: int, 1: string}>, fallback: list<string>}
     */
    public function windowMembers(string $subject, CarbonInterface $since, CarbonInterface $staleBefore): array
    {
        $members = [];
        $fallback = [];
        $cursor = $this->collection()->find(
            ['subject' => $subject, '$or' => [['unlocked_at' => ['$gte' => self::date($since)]], ['fallback' => true]]],
            ['projection' => ['article_id' => 1, 'state' => 1, 'unlocked_at' => 1, 'fallback' => 1]],
        );
        $sinceMs = self::ms($since);
        $staleMs = self::ms($staleBefore);
        foreach ($cursor as $row) {
            if (($row['fallback'] ?? false) === true) {
                $fallback[] = $row['_id'];
            }
            $at = self::msOf($row['unlocked_at']);
            if ($at < $sinceMs) {
                continue;
            }
            if ($row['state'] === self::GRANTED) {
                $members[] = [$at, 'g:'.$row['article_id']];
            } elseif ($at >= $staleMs) {
                $members[] = [$at, 'p:'.$row['article_id']];
            }
        }

        return ['members' => $members, 'fallback' => $fallback];
    }

    public function clearFallback(array $ids): void
    {
        if ($ids !== []) {
            $this->collection()->updateMany(['_id' => ['$in' => $ids]], ['$unset' => ['fallback' => '']]);
        }
    }

    /**
     * Resolve reservations Redis found older than the stale bound: granted → score, abandoned → removed.
     *
     * @param  list<string>  $articleIds
     * @return array<string, ?int>
     */
    public function resolveStale(string $subject, array $articleIds, CarbonInterface $staleBefore): array
    {
        $resolved = array_fill_keys($articleIds, null);
        foreach ($this->collection()->find(['subject' => $subject, 'article_id' => ['$in' => $articleIds]]) as $row) {
            if ($row['state'] === self::GRANTED) {
                $resolved[$row['article_id']] = self::msOf($row['unlocked_at']);
            } elseif (self::msOf($row['unlocked_at']) < self::ms($staleBefore)) {
                $this->collection()->deleteOne(['_id' => $row['_id'], 'state' => self::PENDING]);
            }
        }

        return $resolved;
    }

    /**
     * MongoDB view of a window (Redis down or not yet built): granted plus fresh reservations.
     *
     * @return array{used: int, oldest: ?int}
     */
    public function usage(string $subject, CarbonInterface $windowStart, CarbonInterface $staleBefore, ?string $exclude = null): array
    {
        $filter = [
            'subject' => $subject,
            'unlocked_at' => ['$gte' => self::date($windowStart)],
            '$or' => [['state' => self::GRANTED], ['state' => self::PENDING, 'unlocked_at' => ['$gte' => self::date($staleBefore)]]],
        ];
        if ($exclude !== null) {
            $filter['_id'] = ['$ne' => $exclude];
        }
        $used = $this->collection()->countDocuments($filter);
        $first = $used === 0 ? null : $this->collection()->findOne($filter, ['sort' => ['unlocked_at' => 1], 'projection' => ['unlocked_at' => 1]]);

        return ['used' => $used, 'oldest' => $first ? self::msOf($first['unlocked_at']) : null];
    }

    /**
     * Move a session's granted unlocks to the user; a duplicate article is dropped (the user's unlock wins).
     *
     * @return list<array> the user documents created
     */
    public function transfer(string $sessionId, string $userId, bool $fallback, CarbonInterface $now): array
    {
        $moved = [];
        $guest = self::guestSubject($sessionId);
        $user = self::userSubject($userId);
        foreach ($this->collection()->find(['subject' => $guest, 'state' => self::GRANTED]) as $row) {
            $doc = [
                '_id' => (string) Str::uuid7(),
                'subject' => $user,
                'user_id' => $userId,
                'session_hash' => null,
                'article_id' => $row['article_id'],
                'state' => self::GRANTED,
                'unlocked_at' => $row['unlocked_at'],
                'tier' => $row['tier'] ?? null,
                'origin' => 'transfer',
                'transferred_from' => $row['_id'],
                'created_at' => self::date($now),
                'updated_at' => self::date($now),
            ] + ($fallback ? ['fallback' => true] : []);
            try {
                $this->collection()->insertOne($doc);
                $moved[] = $doc;
            } catch (BulkWriteException $e) {
                if (! self::isDuplicate($e)) {
                    throw $e;
                }
            }
            $this->collection()->deleteOne(['_id' => $row['_id']]);
        }

        return $moved;
    }

    public static function isDuplicate(BulkWriteException $e): bool
    {
        foreach ($e->getWriteResult()->getWriteErrors() as $error) {
            if ($error->getCode() === 11000) {
                return true;
            }
        }

        return false;
    }

    public function collection(): Collection
    {
        return DB::connection('mongodb')->getMongoDB()->selectCollection(self::COLLECTION, ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    public static function carbon(UTCDateTime $date): Carbon
    {
        return Carbon::createFromTimestampMs(self::msOf($date), 'UTC');
    }
}
