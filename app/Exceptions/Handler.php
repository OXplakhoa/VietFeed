<?php

namespace App\Exceptions;

use App\ApiTokens\TokenException;
use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class Handler extends BaseHandler
{
    private function isTokenApi(): bool
    {
        return $this->container->bound('request')
            && $this->container->make('request') instanceof Request
            && $this->container->make('request')->is('api/v1', 'api/v1/*');
    }

    protected function reportThrowable(Throwable $e): void
    {
        // Keep Laravel's reporter and log routing, without sensitive exception messages.
        parent::reportThrowable($this->isTokenApi() && ! $e instanceof TokenException
            ? new RuntimeException('API request failed: '.$e::class) : $e);
    }

    protected function buildExceptionContext(Throwable $e)
    {
        // Neither exception traces/context callbacks nor auth/request context can leak input.
        return $this->isTokenApi() ? [] : parent::buildExceptionContext($e);
    }
}
