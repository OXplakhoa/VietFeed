<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('feeds:fetch')->everyThirtyMinutes();
Schedule::command('unlocks:cleanup')->dailyAt('02:15');
Schedule::command('interactions consume --consumer=scheduler --limit=100')->everyFiveSeconds()->withoutOverlapping(1);
Schedule::command('reading-pass consume --consumer=scheduler --limit=100')->everyFiveSeconds()->withoutOverlapping(1);
// Run separately so CQL waits cannot block other sub-minute tasks. The consumer's alarm is shorter than this mutex.
Schedule::command('sources:history-manage consume --consumer=scheduler --limit=100')->everyFiveSeconds()->withoutOverlapping(1)->runInBackground();

Schedule::command('rbac:audit retry')->everyMinute()->withoutOverlapping(5)->runInBackground();

// Gate 9.7b: retries Stripe cancellations and purges accounts after the recovery window; touches four stores.
Schedule::command('accounts:purge')->hourly()->withoutOverlapping(120)->runInBackground();
