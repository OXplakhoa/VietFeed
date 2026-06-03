<?php

namespace App\Console\Commands;

use App\Models\ArticleUnlock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CleanupOldGuestUnlocks extends Command
{
    protected $signature = 'unlocks:cleanup';

    protected $description = 'Delete guest article unlocks older than 30 days';

    public function handle(): void
    {
        $cutoff = CarbonImmutable::now()->subDays(30);

        $deleted = ArticleUnlock::whereNull('user_id')
            ->whereNotNull('session_id')
            ->where('unlocked_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} old guest unlock(s).");
    }
}
