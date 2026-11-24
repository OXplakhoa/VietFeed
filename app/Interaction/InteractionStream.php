<?php

namespace App\Interaction;

use App\Accounts\AccountLifecycle;
use App\Processing\StreamConsumer;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    public function publishMembership(InteractionEvent $event): bool
    {
        try {
            $this->publish($event->data);

            return true;
        } catch (Throwable $e) {
            Log::warning('Interaction publish failed', ['exception_type' => $e::class]);

            return false;
        }
    }

    public function consume(string $consumer, int $limit = 1, ?callable $handler = null): int
    {
        $activity = null;

        return parent::consume($consumer, $limit, function (array $raw) use ($handler, &$activity): void {
            $event = InteractionEvent::fromArray($raw);
            // Gate 9.7b: a late event must not recreate Cassandra rows for an account that is being or was purged.
            if (app(AccountLifecycle::class)->isPurging($event->data['user_id'] ?? null)) {
                return;
            }
            if ($handler) {
                $handler($event);

                return;
            }

            if ($event->isMembership()) {
                ($activity ??= app(InteractionActivity::class))->apply($event);
            }
            app(Trending::class)->apply($event);
        });
    }
}
