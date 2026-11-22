<?php

namespace App\StoryLens\Brief;

interface BriefModel
{
    public function provider(): string;

    public function model(): ?string;

    /** @throws BriefFailure */
    public function generate(string $title, array $evidence): string;
}
