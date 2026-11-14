<?php

namespace App\Interaction;

final class SyntheticInteractionReplaySource implements InteractionReplaySource
{
    public function events(): iterable
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/interactions-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        if ($fixture['version'] !== 1 || $fixture['synthetic'] !== true) {
            throw new \RuntimeException('Unsupported synthetic replay fixture');
        }
        foreach ($fixture['events'] as $event) {
            yield InteractionEvent::fromArray($event);
        }
    }
}
