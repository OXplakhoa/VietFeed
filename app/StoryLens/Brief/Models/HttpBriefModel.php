<?php

namespace App\StoryLens\Brief\Models;

use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefModel;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class HttpBriefModel implements BriefModel
{
    public function __construct(
        #[\SensitiveParameter] protected string $key,
        protected string $modelName,
        private int $timeout,
        private int $connectTimeout,
    ) {}

    public function model(): ?string
    {
        return $this->modelName ?: null;
    }

    protected function post(string $url, #[\SensitiveParameter] array $headers, array $payload): array
    {
        if ($this->key === '' || $this->modelName === '') {
            throw new BriefFailure('disabled');
        }
        try {
            $response = Http::withHeaders($headers)->acceptJson()->withoutRedirecting()
                ->connectTimeout(min($this->connectTimeout, $this->timeout))->timeout($this->timeout)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            $cause = $e->getPrevious();
            $timedOut = ($cause instanceof ConnectException && ($cause->getHandlerContext()['errno'] ?? null) === 28)
                || preg_match('/cURL error 28\b|\btimed? out\b|\btimeout\b/i', $e->getMessage());
            throw new BriefFailure($timedOut ? 'timeout' : 'network');
        } catch (Throwable) {
            // HTTP client exceptions can carry request URLs, headers and response bodies.
            throw new BriefFailure('network');
        }
        if (! $response->successful()) {
            throw new BriefFailure(match (true) {
                $response->status() === 429 => 'http_429',
                $response->status() === 402 => 'http_402',
                $response->serverError() => 'http_5xx',
                default => 'http_4xx',
            });
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new BriefFailure('invalid_output', 'provider_response');
        }

        return $body;
    }
}
