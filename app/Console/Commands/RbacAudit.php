<?php

namespace App\Console\Commands;

use App\Authorization\AuthorizationAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RbacAudit extends Command
{
    private const RETRY_ALARM_SECONDS = 45;

    protected $signature = 'rbac:audit {action=show : show, schema, retry, dead, or replay}
        {--user= : Audit target ID}
        {--event= : Dead-letter event ID}
        {--all : Replay a bounded batch of dead letters}
        {--limit= : Dead-letter listing or replay batch size (1–500)}
        {--page=1 : Page of dead letters to list}';

    protected $description = 'Read authorization history, provision Cassandra tables, or retry pending deliveries';

    public function handle(AuthorizationAudit $audit): int
    {
        try {
            return match ($this->argument('action')) {
                'show' => $this->show($audit),
                'schema' => $this->schema($audit),
                'retry' => $this->retryPending($audit),
                'dead' => $this->deadLetters($audit),
                'replay' => $this->replay($audit),
                default => self::INVALID,
            };
        } catch (Throwable $e) {
            Log::warning('Authorization audit command failed', ['exception_type' => $e::class]);
            $this->error('Authorization audit unavailable.');

            return self::FAILURE;
        }
    }

    private function show(AuthorizationAudit $audit): int
    {
        $user = $this->option('user');
        $events = $user === null ? $audit->recent() : $audit->forUser($user);
        if ($events === null) {
            $this->error('Authorization audit unavailable.');

            return self::FAILURE;
        }

        $this->table(['At (UTC)', 'Event', 'Actor', 'Target', 'Before', 'After', 'Reason'], array_map(fn (array $event) => [
            (string) $event['occurred_at'], (string) $event['event_id'], $event['actor_id'], $event['target_id'],
            $event['before_state'], $event['after_state'], $event['reason'],
        ], $events));

        return self::SUCCESS;
    }

    private function schema(AuthorizationAudit $audit): int
    {
        $audit->schema();
        $this->info('Authorization audit tables ready.');

        return self::SUCCESS;
    }

    private function deadLetters(AuthorizationAudit $audit): int
    {
        $limit = $this->batchLimit(50);
        $page = filter_var($this->option('page'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(2_147_483_647, AuthorizationAudit::MAX_DEAD_LETTER_BATCH)],
        ]);
        if ($limit === false || $page === false) {
            $this->error('Use a positive --page and a --limit between 1 and 500.');

            return self::INVALID;
        }

        $this->table(['Event', 'Target', 'Attempts', 'Reason'], array_map(fn (array $document) => [
            $document['_id'], $document['event']['target'] ?? '', $document['attempts'] ?? 0, $document['event']['reason'] ?? '',
        ], $audit->deadLetters($this->option('user'), $limit, ($page - 1) * $limit)));

        return self::SUCCESS;
    }

    private function replay(AuthorizationAudit $audit): int
    {
        $event = $this->option('event');
        if ($this->option('all')) {
            $limit = $this->batchLimit(100);
            if ($event !== null || $limit === false) {
                $this->error('Use --all without --event and a --limit between 1 and 500.');

                return self::INVALID;
            }

            $this->info('Requeued: '.$audit->replayAll($limit).'.');

            return self::SUCCESS;
        }

        if (! is_string($event) || ! $audit->replay($event)) {
            $this->error('Dead-letter event not found.');

            return self::FAILURE;
        }
        $this->info('Event queued for retry.');

        return self::SUCCESS;
    }

    private function batchLimit(int $default): int|false
    {
        return filter_var($this->option('limit') ?? $default, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => AuthorizationAudit::MAX_DEAD_LETTER_BATCH],
        ]);
    }

    private function retryPending(AuthorizationAudit $audit): int
    {
        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')) {
            $this->info('Delivered: '.$audit->retry().'.');

            return self::SUCCESS;
        }

        // Terminate an unresponsive socket call before the five-minute overlap lock expires.
        $started = hrtime(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, SIG_DFL);
        $previousAlarm = pcntl_alarm(self::RETRY_ALARM_SECONDS);
        try {
            $this->info('Delivered: '.$audit->retry().'.');
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            if ($previousAlarm > 0) {
                $elapsed = (int) ((hrtime(true) - $started) / 1_000_000_000);
                pcntl_alarm(max(1, $previousAlarm - $elapsed));
            }
        }

        return self::SUCCESS;
    }
}
