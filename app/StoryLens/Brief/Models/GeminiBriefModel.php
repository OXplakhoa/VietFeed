<?php

namespace App\StoryLens\Brief\Models;

use App\StoryLens\Brief\BriefFailure;
use App\StoryLens\Brief\BriefPrompt;

final class GeminiBriefModel extends HttpBriefModel
{
    public function __construct(
        #[\SensitiveParameter] string $key,
        string $modelName,
        int $timeout,
        int $connectTimeout,
        private string $thinkingLevel = '',
    ) {
        parent::__construct($key, $modelName, $timeout, $connectTimeout);
    }

    public function provider(): string
    {
        return 'gemini';
    }

    public function generate(string $title, array $evidence): string
    {
        $generation = [
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => BriefPrompt::SCHEMA,
            'maxOutputTokens' => 16384,
        ];
        $level = strtoupper(trim($this->thinkingLevel));
        if (in_array($level, ['MINIMAL', 'LOW', 'MEDIUM', 'HIGH'], true)) {
            $generation['thinkingConfig'] = ['thinkingLevel' => $level];
        }
        $body = $this->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($this->modelName).':generateContent',
            ['x-goog-api-key' => $this->key],
            [
                'systemInstruction' => ['parts' => [['text' => BriefPrompt::INSTRUCTIONS]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => BriefPrompt::input($title, $evidence)]]]],
                'generationConfig' => $generation,
            ],
        );
        $candidate = $body['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];
        if (($candidate['finishReason'] ?? null) !== 'STOP' || ! is_array($parts) || ! empty($body['promptFeedback']['blockReason'])) {
            throw new BriefFailure('invalid_output', 'provider_incomplete');
        }
        $output = '';
        foreach ($parts as $part) {
            if (($part['thought'] ?? false) === true) {
                continue;
            }
            if (! is_string($part['text'] ?? null)) {
                throw new BriefFailure('invalid_output', 'provider_response');
            }
            $output .= $part['text'];
        }

        return $output;
    }
}
