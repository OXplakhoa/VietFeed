<?php

namespace App\Interaction;

use App\Models\InteractionMembership;
use MongoDB\Driver\Exception\BulkWriteException;

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

            return ['action' => $added ? 'added' : 'removed', 'count' => $model::where('article_id', $articleId)->count()];
        }
    }
}
