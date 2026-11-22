<?php

namespace App\StoryLens\Brief;

final class BriefFailure extends \RuntimeException
{
    public function __construct(public readonly string $category, public readonly ?string $reason = null)
    {
        parent::__construct($category);
    }

    public function isRetryable(): bool
    {
        return in_array($this->category, ['http_5xx', 'timeout', 'network'], true);
    }
}
