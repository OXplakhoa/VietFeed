<?php

namespace App\StoryLens\Brief\Models;

use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefModel;

final class DisabledBriefModel implements BriefModel
{
    public function provider(): string
    {
        return 'none';
    }

    public function model(): ?string
    {
        return null;
    }

    public function generate(string $title, array $evidence): string
    {
        throw new BriefFailure('disabled');
    }
}
