<?php

namespace App\Interaction;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class InteractionEvent
{
    private function __construct(public array $data) {}

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
        if (! in_array($data['type'], ['view', 'boost'], true)) {
            throw new InvalidArgumentException('Unsupported interaction type');
        }

        $data['event_id'] = strtolower($data['event_id']);
        $data['aggregate_id'] = strtolower($data['aggregate_id']);

        return new self($data);
    }
}
