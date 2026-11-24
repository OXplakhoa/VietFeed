<?php

namespace App\Interaction;

use App\Models\InteractionMembership;
use Carbon\CarbonImmutable;
use MongoDB\Driver\Exception\BulkWriteException;
use RuntimeException;

final class InteractionToggle
{
    public function __construct(private InteractionStream $stream) {}

    /** @param class-string<InteractionMembership> $model */
    public function run(string $model, string $userId, string $articleId): array
    {
        $key = ['user_id' => $userId, 'article_id' => $articleId];
        while (true) {
            $membership = $model::where($key)->first();
            if ($membership !== null) {
                // Delete this incarnation only. Another request may have already replaced it.
                if ($model::whereKey($membership->getKey())->delete() !== 1) {
                    continue;
                }
                $added = false;
            } else {
                try {
                    $membership = $model::create($key);
                } catch (BulkWriteException $e) {
                    if ($e->getCode() !== 11000) {
                        throw $e;
                    }

                    continue;
                }
                $added = true;
            }

            $this->stream->publishMembership(InteractionEvent::membership($membership, $added));

            return ['action' => $added ? 'added' : 'removed', 'count' => $model::real()->where('article_id', $articleId)->count()];
        }
    }

    /** @param class-string<InteractionMembership> $model */
    public function seedSynthetic(string $model, string $userId, string $articleId, int $seed, string $eventId, CarbonImmutable $at): void
    {
        $key = ['user_id' => $userId, 'article_id' => $articleId];
        $membership = $model::unguarded(fn () => $model::firstOrCreate($key, [
            '_id' => $eventId,
            'synthetic' => true,
            'synthetic_seed' => $seed,
            'created_at' => $at,
            'updated_at' => $at,
        ]));
        if ($membership->id !== $eventId || $membership->synthetic !== true || $membership->synthetic_seed !== $seed) {
            throw new RuntimeException('Synthetic membership conflicts with an existing record.');
        }
        if (! $this->stream->publishMembership(InteractionEvent::membership($membership, added: true))) {
            throw new RuntimeException('Synthetic interaction could not be published. Retry the same seed.');
        }
    }
}
