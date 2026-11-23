<?php

namespace App\Console\Commands;

use App\Authorization\AuthorizationConflict;
use App\Authorization\Permissions;
use App\Authorization\SuperAdminGuard;
use App\Authorization\UserAuthorization;
use App\Models\User;
use Illuminate\Console\Command;

final class RbacBootstrap extends Command
{
    protected $signature = 'rbac:bootstrap {--super-admin= : Email of the initial Super Admin}
        {--clear-candidate= : Exact user ID from an abandoned bootstrap}';

    protected $description = 'Initialize Super Admin membership and preserve access for legacy admins during cutover';

    public function handle(SuperAdminGuard $guard, UserAuthorization $authorization): int
    {
        $email = $this->option('super-admin');
        $candidate = $this->option('clear-candidate');
        if ($candidate !== null) {
            if ($email !== null || $candidate === '') {
                $this->error('Use --clear-candidate=<id> separately from --super-admin.');

                return self::INVALID;
            }
            if (! $guard->clearBootstrapCandidate($candidate)) {
                $this->error('The candidate changed, bootstrap is complete, or a guard repair is running.');

                return self::FAILURE;
            }
            $this->info('Cleared the bootstrap candidate marker.');

            return self::SUCCESS;
        }

        $initial = is_string($email) && $email !== '' ? User::where('email', $email)->first() : null;
        if ($initial === null) {
            $this->error('An existing user email is required for --super-admin.');

            return self::FAILURE;
        }

        $initialized = $guard->bootstrapUserId();
        if ($initialized !== null && $initialized !== (string) $initial->id) {
            $this->error('RBAC has already been bootstrapped with another user.');

            return self::FAILURE;
        }
        if ($initialized !== null) {
            $this->info('Promoted: 0. Legacy admins granted permissions: 0.');

            return self::SUCCESS;
        }
        if (! $authorization->canBecomeSuperAdmin($initial) || $initial->getAttribute('authorization_deleting')) {
            $this->error('The initial Super Admin account must be active.');

            return self::FAILURE;
        }

        if (! $guard->beginBootstrap((string) $initial->id)) {
            $this->error('Another bootstrap or guard repair is in progress.');
            $recordedCandidate = $guard->bootstrapCandidateId();
            if ($recordedCandidate !== null && $recordedCandidate !== (string) $initial->id) {
                $this->line('Recorded candidate ID: '.$recordedCandidate);
                $this->line('After confirming its bootstrap process has stopped, use --clear-candidate with that ID.');
            }

            return self::FAILURE;
        }

        try {
            $promoted = $authorization->bootstrap($initial, Permissions::SUPER_ADMIN, []) ? 1 : 0;
        } catch (AuthorizationConflict $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $granted = 0;
        foreach (User::where('role', Permissions::ADMIN)->whereNull('authorization_version')->cursor() as $admin) {
            try {
                $granted += (int) $authorization->bootstrap($admin, Permissions::ADMIN, Permissions::ALL);
            } catch (AuthorizationConflict) {
                // A concurrent authorization operation wins over the legacy grant.
            }
        }

        if (! $guard->finishBootstrap((string) $initial->id) && $guard->bootstrapUserId() !== (string) $initial->id) {
            $this->error('Super Admin membership is unavailable. Inspect rbac:repair --guard before retrying.');

            return self::FAILURE;
        }
        $this->info("Promoted: $promoted. Legacy admins granted permissions: $granted.");

        return self::SUCCESS;
    }
}
