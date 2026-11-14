<?php

namespace App\Interaction;

interface InteractionReplaySource
{
    /** @return iterable<InteractionEvent> Ordered immutable events, oldest version first. */
    public function events(): iterable;
}
