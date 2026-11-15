<?php

namespace App\Interaction;

use App\Processing\StreamConsumer;

final class InteractionStream extends StreamConsumer
{
    public const STREAM = 'interactions:events:v1';

    public const GROUP = 'trending-v1';

    public const DLQ = 'interactions:dead:v1';

    public const ERRORS = 'interactions:errors:v1';

    public function __construct(int $retryMilliseconds = 1000, int $pendingPageSize = 1000)
    {
        parent::__construct(self::STREAM, self::GROUP, self::DLQ, self::ERRORS, $retryMilliseconds, $pendingPageSize);
    }

    public function consume(string $consumer, int $limit = 1, ?callable $handler = null): int
    {
        return parent::consume($consumer, $limit, function (array $raw) use ($handler): void {
            $event = InteractionEvent::fromArray($raw);
            $handler ? $handler($event) : app(Trending::class)->apply($event);
        });
    }
}
