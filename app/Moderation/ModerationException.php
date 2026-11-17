<?php

namespace App\Moderation;

use RuntimeException;

/** A moderation rule refusal whose message is safe to show to the acting user. */
class ModerationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
