<?php

namespace App\Services\Rss;

class FeedRunResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $httpStatus,
        public readonly int $itemsFound,
        public readonly int $itemsCreated,
        public readonly int $itemsUpdated,
        public readonly int $validItems,
        public readonly int $invalidItems,
        public readonly int $missingImageCount,
        public readonly int $dateParseErrorCount,
        public readonly int $duplicateCount,
        public readonly int $durationMs,
        public readonly array $sampleItems = [],
    ) {}
}
