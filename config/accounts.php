<?php

return [
    // Gate 9.7b: days an account stays recoverable between a deletion request and the purge.
    'recovery_days' => (int) env('ACCOUNT_RECOVERY_DAYS', 30),
];
