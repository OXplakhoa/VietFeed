<?php

namespace App\Console;

use RuntimeException;

final class ProjectionConsumeBudget
{
    private const WORK_SECONDS = 240;

    private const ALARM_SECONDS = 270;

    /** @param callable(): int $consumeOne Returns 1 for a delivery or 0 when idle. */
    public static function run(string $consumer, int $limit, callable $consumeOne): int
    {
        $started = hrtime(true);
        $deadline = $started + self::WORK_SECONDS * 1_000_000_000;
        if ($consumer !== 'scheduler') {
            return self::consume($deadline, $limit, $consumeOne);
        }
        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')
            || ! function_exists('pcntl_signal_dispatch')) {
            throw new RuntimeException('Scheduled projection consumers require PCNTL to stop before the overlap lock expires.');
        }

        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $alarmStarted = hrtime(true);
        $previousAlarm = pcntl_alarm(0);
        if ($previousAlarm === 0 && is_callable($previousHandler)) {
            // PHP may already have queued the signal while synchronous dispatch was in use.
            pcntl_signal_dispatch();
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            $alarmStarted = hrtime(true);
            $previousAlarm = pcntl_alarm(0);
        }
        $alarm = $previousAlarm > 0 ? min($previousAlarm, self::ALARM_SECONDS) : self::ALARM_SECONDS;
        if ($previousAlarm === $alarm) {
            // Let the earlier alarm elapse with its own handler, even after this call returns.
            pcntl_alarm($alarm);

            return self::consume($deadline, $limit, $consumeOne);
        }

        try {
            // The OS default terminates the CLI even during a blocked socket call.
            if (! pcntl_signal(SIGALRM, SIG_DFL)) {
                throw new RuntimeException('Could not install the projection consumer alarm handler.');
            }
            pcntl_alarm($alarm);

            return self::consume($deadline, $limit, $consumeOne);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            $elapsed = intdiv(hrtime(true) - $alarmStarted, 1_000_000_000);
            if ($previousAlarm > $elapsed) {
                pcntl_alarm($previousAlarm - $elapsed);
            }
        }
    }

    private static function consume(int $deadline, int $limit, callable $consumeOne): int
    {
        $count = 0;
        while ($count < $limit && hrtime(true) < $deadline && $consumeOne() === 1) {
            $count++;
        }

        return $count;
    }
}
