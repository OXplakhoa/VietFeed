<?php

namespace App\Services\Rss\Exceptions;

use RuntimeException;

class FeedFetchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorType,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
