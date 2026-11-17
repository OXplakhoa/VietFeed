<?php

namespace App\Moderation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Canonical moderation audit event envelope (spec §10.5). Events are written into the Mongo case or
 * sanction document in the same atomic update as the state change, then published to the stream.
 */
final class ModerationEvents
{
    public const TYPES = [
        'REPORTED', 'CASE_DISMISSED', 'CASE_FALSE_REPORT', 'SANCTION_ISSUED', 'COMMENT_HIDDEN', 'COMMENT_UNHIDDEN',
        'APPEAL_SUBMITTED', 'APPEAL_APPROVED', 'APPEAL_DENIED', 'SANCTION_REVOKED',
    ];

    public const NOTE_LIMIT = 280;

    /**
     * @param  'case'|'sanction'  $aggregateType
     *                                            `aggregate_version` is the version of the document that recorded the event; `case_version` is the
     *                                            case document's version (equal for case events, the case version at write time for case-linked
     *                                            sanction events, null for sanctions without a case).
     * @param  array{report_id?: ?string, sanction_id?: ?string, comment_id?: ?int, round?: ?int, occurred_at?: string, event_id?: string, case_version?: ?int}  $refs
     */
    public static function make(string $type, string $caseId, string $aggregateType, string $aggregateId, int $version, ?string $actorId, ?string $note = null, array $refs = []): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown moderation event type');
        }

        return [
            'event_id' => $refs['event_id'] ?? (string) Str::uuid7(),
            'type' => $type,
            'case_id' => $caseId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'aggregate_version' => $version,
            'case_version' => array_key_exists('case_version', $refs) ? ($refs['case_version'] === null ? null : (int) $refs['case_version']) : ($aggregateType === 'case' ? $version : null),
            'occurred_at' => $refs['occurred_at'] ?? self::now(),
            'actor_id' => $actorId,
            'note' => self::note($note),
            'report_id' => $refs['report_id'] ?? null,
            'sanction_id' => $refs['sanction_id'] ?? null,
            'comment_id' => isset($refs['comment_id']) ? (int) $refs['comment_id'] : null,
            'round' => isset($refs['round']) ? (int) $refs['round'] : null,
        ];
    }

    public static function now(): string
    {
        return self::time(CarbonImmutable::now('UTC'));
    }

    public static function time(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function note(?string $note): ?string
    {
        return $note === null || $note === '' ? null : Str::limit($note, self::NOTE_LIMIT, '…');
    }

    /** Wrap literal strings so an aggregation-pipeline update never reads user text as a field path. */
    public static function literal(array $event): array
    {
        return array_map(fn ($value) => is_string($value) ? ['$literal' => $value] : $value, $event);
    }

    /** Plain PHP array from a BSON document/array (typeMap independent), for strict comparison. */
    public static function plain(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
