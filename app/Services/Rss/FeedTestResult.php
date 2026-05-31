<?php

namespace App\Services\Rss;

class FeedTestResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $httpStatus,
        public readonly int $itemsFound,
        public readonly int $validItems,
        public readonly int $invalidItems,
        public readonly int $missingImageCount,
        public readonly int $dateParseErrorCount,
        public readonly int $durationMs,
        public readonly array $sampleItems = [],
        public readonly ?string $errorType = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
