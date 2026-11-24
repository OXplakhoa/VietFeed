<?php

namespace App\Console\Commands;

use App\Accounts\AccountPurge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AccountsPurge extends Command
{
    protected $signature = 'accounts:purge {--limit=100 : Maximum workflows handled per phase (1-500)} {--dry-run : Print what each step would touch and change nothing}';

    protected $description = 'Retry Stripe cancellations and purge accounts whose recovery window has ended';

    public function handle(AccountPurge $purge): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) {
            $this->error('--limit must be 1..500');

            return self::INVALID;
        }
        try {
            $result = $purge->run($limit, (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            // Never print store messages: they can carry identifiers.
            Log::warning('Account purge failed', ['exception_type' => $e::class]);
            $this->error('Account purge unavailable.');

            return self::FAILURE;
        }
        $steps = $result['steps'];
        unset($result['steps']);
        $this->info(($this->option('dry-run') ? 'Dry run: ' : '').collect($result)->map(fn ($n, $k) => "$k=$n")->implode(' '));
        if ($this->option('dry-run')) {
            $this->table(['Step', 'Would touch'], collect($steps)->map(fn ($n, $k) => [$k, $n])->values()->all());
        }

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
