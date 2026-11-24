<?php

namespace App\Console\Commands;

use App\ReadingPass\UnlockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CleanupOldGuestUnlocks extends Command
{
    protected $signature = 'unlocks:cleanup';

    protected $description = 'Delete guest article unlocks older than 30 days and abandoned reservations';

    public function handle(UnlockLedger $ledger): void
    {
        $cutoff = CarbonImmutable::now()->subDays(30);

        // Redis windows expire on their own (TTL) and guest Cassandra history carries a 30-day TTL.
        $deleted = $ledger->collection()->deleteMany([
            'user_id' => null,
            'subject' => ['$regex' => '^'.UnlockLedger::GUEST_PREFIX],
            'unlocked_at' => ['$lt' => UnlockLedger::date($cutoff)],
        ])->getDeletedCount();

        // A reservation is decided within one request; anything pending this long belongs to a crashed request.
        $stale = ['$lt' => UnlockLedger::date(CarbonImmutable::now()->subHour())];
        $abandoned = $ledger->collection()->deleteMany([
            'state' => UnlockLedger::PENDING,
            '$or' => [
                ['synthetic' => ['$ne' => true], 'unlocked_at' => $stale],
                ['synthetic' => true, 'created_at' => $stale],
            ],
        ])->getDeletedCount();

        $this->info("Deleted {$deleted} old guest unlock(s).");
        $this->info("Deleted {$abandoned} abandoned reservation(s).");
    }
}
