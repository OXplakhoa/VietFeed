<?php

namespace App\SourceHistory;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final readonly class SourceFetchAttempt
{
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $type,
        public string $startedAt,
    ) {}

    public static function begin(string $sourceId, string $type, DateTimeInterface $at): self
    {
        return new self((string) Str::uuid7($at), $sourceId, $type, self::timestamp($at));
    }

    public static function timestamp(DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
