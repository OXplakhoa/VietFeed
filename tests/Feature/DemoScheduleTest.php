<?php

namespace Tests\Feature;

use App\Console\ProjectionConsumeBudget;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class DemoScheduleTest extends TestCase
{
    public function test_demo_consumers_and_repair_are_scheduled_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events());
        foreach ([
            'storylens consume --section=timeline --consumer=scheduler --limit=100',
            'storylens consume --section=graph --consumer=scheduler --limit=100',
            'moderation:audit consume --consumer=scheduler --limit=100',
            'moderation:repair',
        ] as $command) {
            $event = $events->sole(fn ($event) => str_ends_with($event->command, ' '.$command));
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->runInBackground);
            $this->assertSame($command === 'moderation:repair' ? 30 : 5, $event->expiresAt);
            $this->assertSame($command === 'moderation:repair' ? null : 10, $event->repeatSeconds);
            $this->assertSame($command === 'moderation:repair' ? '*/5 * * * *' : '* * * * *', $event->expression);
        }

        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler')
            && function_exists('pcntl_signal_dispatch') && function_exists('pcntl_async_signals')) {
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            $previousAlarm = pcntl_alarm(0);
            $previousAsync = pcntl_async_signals(false);
            $signals = 0;
            $handler = static function () use (&$signals): void {
                $signals++;
            };
            pcntl_signal(SIGALRM, $handler);
            try {
                $this->assertSame(1, ProjectionConsumeBudget::run('local', 1, function () use ($handler): int {
                    $this->assertSame($handler, pcntl_signal_get_handler(SIGALRM));
                    $this->assertSame(0, pcntl_alarm(0));

                    return 1;
                }));

                pcntl_alarm(360);
                $deliveries = 0;
                $count = ProjectionConsumeBudget::run('scheduler', 2, function () use (&$deliveries): int {
                    $this->assertSame(SIG_DFL, pcntl_signal_get_handler(SIGALRM));
                    $alarm = pcntl_alarm(0);
                    $this->assertGreaterThan(0, $alarm);
                    $this->assertLessThanOrEqual(270, $alarm);
                    pcntl_alarm($alarm);
                    $deliveries++;

                    return 1;
                });
                $this->assertSame(2, $count);
                $this->assertSame(2, $deliveries);
                $this->assertSame($handler, pcntl_signal_get_handler(SIGALRM));
                $this->assertGreaterThan(270, pcntl_alarm(0));

                pcntl_alarm(120);
                $this->assertSame(1, ProjectionConsumeBudget::run('scheduler', 1, function () use ($handler): int {
                    $this->assertSame($handler, pcntl_signal_get_handler(SIGALRM));

                    return 1;
                }));
                $this->assertSame($handler, pcntl_signal_get_handler(SIGALRM));
                $remaining = pcntl_alarm(0);
                $this->assertGreaterThan(0, $remaining);
                $this->assertLessThanOrEqual(120, $remaining);

                pcntl_alarm(1);
                $this->assertSame(1, ProjectionConsumeBudget::run('scheduler', 1, function (): int {
                    sleep(2);
                    pcntl_signal_dispatch();

                    return 1;
                }));
                $this->assertSame(1, $signals);
                $this->assertSame(0, pcntl_alarm(0));

                pcntl_alarm(1);
                sleep(2);
                $this->assertSame(1, $signals);
                $this->assertSame(1, ProjectionConsumeBudget::run('scheduler', 1, function () use (&$signals): int {
                    $this->assertSame(2, $signals);

                    return 1;
                }));
                $this->assertSame(0, pcntl_alarm(0));
            } finally {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $previousHandler);
                pcntl_async_signals($previousAsync);
                pcntl_alarm($previousAlarm);
            }
        }
    }
}
