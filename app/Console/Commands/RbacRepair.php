<?php

namespace App\Console\Commands;

use App\Authorization\AuthorizationRepair;
use Illuminate\Console\Command;
use RuntimeException;

final class RbacRepair extends Command
{
    protected $signature = 'rbac:repair {--user= : MongoDB user ID to inspect}
        {--guard : Reconcile Super Admin membership} {--force : Apply the repair}';

    protected $description = 'Inspect or repair stale authorization markers and Super Admin membership';

    public function handle(AuthorizationRepair $repair): int
    {
        $user = $this->option('user');
        $guard = $this->option('guard');
        if ($guard === ($user !== null) || $user === '') {
            $this->error('Choose exactly one of --user=<id> and --guard.');

            return self::INVALID;
        }
        $force = $this->option('force');
        $writersPaused = $this->laravel->isDownForMaintenance();
        $this->info($force ? 'Applying repair.' : 'Dry run.');
        try {
            if ($guard) {
                $changes = $repair->guard($force, $writersPaused);
                $this->line('Add members: '.($changes['added'] === [] ? 'none' : implode(', ', $changes['added'])));
                $this->line('Remove members: '.($changes['removed'] === [] ? 'none' : implode(', ', $changes['removed'])));
            } else {
                $markers = $repair->user($user, $force, $writersPaused);
                $this->line('Stale markers: '.($markers === [] ? 'none' : implode(', ', $markers)));
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
