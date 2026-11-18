<?php

namespace App\Interaction;

use App\Models\Boost;
use App\Models\InteractionMembership;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class InteractionEvent
{
    private const MEMBERSHIP_VERSIONS = ['BOOKMARKED' => 1, 'UNBOOKMARKED' => 2, 'BOOSTED' => 1, 'UNBOOSTED' => 2];

    private function __construct(public array $data) {}

    public static function membership(InteractionMembership $membership, bool $added): self
    {
        $type = $membership instanceof Boost
            ? ($added ? 'BOOSTED' : 'UNBOOSTED')
            : ($added ? 'BOOKMARKED' : 'UNBOOKMARKED');
        $id = (string) $membership->getKey();
        $time = $added ? $membership->created_at : now();

        return self::fromArray([
            'event_id' => $added ? $id : (string) Str::uuid7(),
            'type' => $type,
            'aggregate_id' => $id,
            'aggregate_version' => self::MEMBERSHIP_VERSIONS[$type],
            'occurred_at' => $time->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'user_id' => $membership->user_id,
            'article_id' => $membership->article_id,
            'backfill' => $added && $membership->legacy_sql_id !== null,
        ]);
    }

    public function isMembership(): bool
    {
        return isset(self::MEMBERSHIP_VERSIONS[$this->data['type']]);
    }

    public static function fromArray(array $data): self
    {
        foreach (['event_id', 'type', 'aggregate_id', 'occurred_at', 'article_id'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new InvalidArgumentException("Missing or invalid $field");
            }
        }
        if (! Uuid::isValid($data['event_id']) || Uuid::fromString($data['event_id'])->getVersion() !== 7) {
            throw new InvalidArgumentException('event_id must be UUIDv7');
        }
        if (! Uuid::isValid($data['aggregate_id']) || Uuid::fromString($data['aggregate_id'])->getVersion() !== 7) {
            throw new InvalidArgumentException('aggregate_id must be UUIDv7');
        }
        if (! is_int($data['aggregate_version'] ?? null) || $data['aggregate_version'] < 1) {
            throw new InvalidArgumentException('aggregate_version must be a positive integer');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $data['occurred_at']) || strtotime($data['occurred_at']) === false) {
            throw new InvalidArgumentException('occurred_at must be an explicit RFC3339 timestamp');
        }
        $membershipVersion = self::MEMBERSHIP_VERSIONS[$data['type']] ?? null;
        if ($membershipVersion !== null) {
            if (! is_string($data['user_id'] ?? null) || $data['user_id'] === ''
                || $data['aggregate_version'] !== $membershipVersion
                || (array_key_exists('backfill', $data) && ! is_bool($data['backfill']))) {
                throw new InvalidArgumentException('Invalid membership event');
            }
        } elseif (! in_array($data['type'], ['view', 'boost'], true)) {
            throw new InvalidArgumentException('Unsupported interaction type');
        }

        $data['event_id'] = strtolower($data['event_id']);
        $data['aggregate_id'] = strtolower($data['aggregate_id']);

        return new self($data);
    }
}
