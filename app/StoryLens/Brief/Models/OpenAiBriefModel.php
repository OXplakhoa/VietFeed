<?php

namespace App\StoryLens\Brief\Models;

use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefPrompt;

final class OpenAiBriefModel extends HttpBriefModel
{
    public function __construct(
        private string $baseUrl,
        #[\SensitiveParameter] string $key,
        string $modelName,
        int $timeout,
        int $connectTimeout,
    ) {
        parent::__construct($key, $modelName, $timeout, $connectTimeout);
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function generate(string $title, array $evidence): string
    {
        $parts = parse_url($this->baseUrl);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $localHttp = $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (! filter_var($this->baseUrl, FILTER_VALIDATE_URL) || ($scheme !== 'https' && ! $localHttp)
            || array_intersect_key($parts, array_flip(['user', 'pass', 'query', 'fragment']))) {
            throw new BriefFailure('disabled');
        }
        $body = $this->post(rtrim($this->baseUrl, '/').'/chat/completions', ['Authorization' => 'Bearer '.$this->key], [
            'model' => $this->modelName,
            'messages' => [
                ['role' => 'system', 'content' => BriefPrompt::INSTRUCTIONS],
                ['role' => 'user', 'content' => BriefPrompt::input($title, $evidence)],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);
        $choice = $body['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        if (($choice['finish_reason'] ?? null) !== 'stop' || ! empty($message['refusal']) || ! is_string($message['content'] ?? null)) {
            throw new BriefFailure('invalid_output', 'provider_incomplete');
        }

        return $message['content'];
    }
}
