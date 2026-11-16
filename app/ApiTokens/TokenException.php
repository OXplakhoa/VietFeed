<?php

namespace App\ApiTokens;

use RuntimeException;

final class TokenException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 401)
    {
        parent::__construct($errorCode);
    }
}
