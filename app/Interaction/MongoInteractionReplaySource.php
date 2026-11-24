<?php

namespace App\Interaction;

use App\Models\Bookmark;
use App\Models\Boost;

final class MongoInteractionReplaySource implements InteractionReplaySource
{
    public function events(): iterable
    {
        foreach (Boost::real()->lazyById(500, '_id', 'id') as $boost) {
            yield InteractionEvent::membership($boost, added: true);
        }
    }

    public function reemit(InteractionStream $stream): array
    {
        $published = $failed = 0;
        foreach ([Bookmark::class, Boost::class] as $model) {
            foreach ($model::query()->lazyById(500, '_id', 'id') as $membership) {
                if ($stream->publishMembership(InteractionEvent::membership($membership, added: true))) {
                    $published++;
                } else {
                    $failed++;
                }
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }
}
