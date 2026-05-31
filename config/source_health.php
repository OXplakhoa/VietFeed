<?php

return [
    'stale_after_hours' => env('SOURCE_HEALTH_STALE_AFTER_HOURS', 6),
    'critical_after_failures' => env('SOURCE_HEALTH_CRITICAL_AFTER_FAILURES', 3),
    'backoff' => [5, 30, 120, 600, 1800],
    'warning' => [
        'valid_item_rate_below' => 0.8,
        'duplicate_rate_above' => 0.9,
        'slow_duration_ms_above' => 10000,
    ],
];
