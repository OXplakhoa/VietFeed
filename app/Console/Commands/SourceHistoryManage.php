<?php

namespace App\Console\Commands;

use App\SourceHistory\SourceFetchHistory;
use Illuminate\Console\Command;
use Throwable;

class SourceHistoryManage extends Command
{
    private const CONSUME_BUDGET_SECONDS = 35;

    private const CONSUME_ALARM_SECONDS = 45;

    protected $signature = 'sources:history-manage {action : bootstrap|consume|inspect|replay}
        {--consumer=cli : Redis Stream consumer name}
        {--limit=100 : Maximum deliveries to consume}
        {--id= : Dead-letter entry to replay}';

    protected $description = 'Bootstrap Cassandra fetch history or operate its Redis projection';

    public function handle(SourceFetchHistory $history): int
    {
        try {
            switch ($this->argument('action')) {
                case 'bootstrap':
                    $history->bootstrap();
                    $this->info('Source fetch history schema ready.');
                    break;
                case 'consume':
                    $this->line('Processed: '.$this->consume($history));
                    break;
                case 'inspect':
                    $stream = $history->stream();
                    foreach ($stream->lengths() as $name => $count) {
                        $this->line("$name: $count");
                    }
                    foreach ($stream->deadLetters() as $id => $entry) {
                        $this->line('Dead-letter '.$id.' (attempts: '.$entry['attempts'].')');
                    }
                    break;
                case 'replay':
                    $id = (string) $this->option('id');
                    if (! preg_match('/^\d+-\d+$/D', $id)) {
                        $this->error('Provide a valid --id to replay.');

                        return self::INVALID;
                    }
                    $this->line('Requeued: '.$history->stream()->replay($id));
                    break;
                default:
                    $this->error('Choose bootstrap, consume, inspect or replay.');

                    return self::INVALID;
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Source fetch history operation failed ('.$e::class.').');

            return self::FAILURE;
        }
    }

    private function consume(SourceFetchHistory $history): int
    {
        $deadline = hrtime(true) + self::CONSUME_BUDGET_SECONDS * 1_000_000_000;
        $hasAlarm = function_exists('pcntl_alarm') && function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler');
        if ($hasAlarm) {
            // Default SIGALRM terminates even while the CQL driver waits inside a socket call.
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, SIG_DFL);
            $previousAlarm = pcntl_alarm(self::CONSUME_ALARM_SECONDS);
        }

        try {
            return $history->consume(
                (string) $this->option('consumer'),
                (int) $this->option('limit'),
                fn () => hrtime(true) < $deadline,
            );
        } finally {
            if ($hasAlarm) {
                pcntl_alarm(0);
                pcntl_signal(SIGALRM, $previousHandler);
                if ($previousAlarm > 0) {
                    $remaining = max(1, (int) ceil(($deadline - hrtime(true)) / 1_000_000_000) + $previousAlarm - self::CONSUME_BUDGET_SECONDS);
                    pcntl_alarm($remaining);
                }
            }
        }
    }
}
