<?php

return [
    'provider' => env('AI_PROVIDER', 'none'),
    'gemini' => [
        'key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
        'thinking_level' => env('GEMINI_THINKING_LEVEL', ''),
    ],
    'openai' => [
        'base_url' => env('OPENAI_COMPAT_BASE_URL', ''),
        'key' => env('OPENAI_COMPAT_API_KEY', ''),
        'model' => env('OPENAI_COMPAT_MODEL', ''),
    ],
    'timeout_seconds' => max(1, min(60, (int) env('AI_TIMEOUT_SECONDS', 10))),
    'connect_timeout_seconds' => max(1, (int) env('AI_CONNECT_TIMEOUT_SECONDS', 3)),
    'max_evidence' => max(1, min(30, (int) env('AI_MAX_EVIDENCE', 8))),
    'dispatch_spacing_seconds' => max(0, (int) env('AI_DISPATCH_SPACING_SECONDS', 6)),
    'retry_delay_seconds' => max(0, (int) env('AI_RETRY_DELAY_SECONDS', 30)),
    'sync_retry_delay_seconds' => max(0, (int) env('AI_SYNC_RETRY_DELAY_SECONDS', 3)),
    'require_review' => (bool) env('AI_BRIEF_REQUIRE_REVIEW', true),
];
