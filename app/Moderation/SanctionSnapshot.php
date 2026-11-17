<?php

namespace App\Moderation;

use App\Models\Sanction;
use Illuminate\Support\Collection;

/**
 * Per-request memo of a user's currently active sanctions: one MongoDB read per user per HTTP
 * request, so the middleware, controller and Blade all see the same snapshot (Gate 9a R1 / the
 * single-read snapshot test). Outside a request (CLI, tests between requests) nothing is memoised.
 * Every sanction write forgets the user's entry.
 */
final class SanctionSnapshot
{
    private bool $active = false;

    /** @var array<string, Collection<int, Sanction>> */
    private array $memo = [];

    public function begin(): void
    {
        $this->memo = [];
        $this->active = true;
    }

    public function end(): void
    {
        $this->memo = [];
        $this->active = false;
    }

    public function forget(?string $userId = null): void
    {
        if ($userId === null) {
            $this->memo = [];
        } else {
            unset($this->memo[$userId]);
        }
    }

    /** @return Collection<int, Sanction> newest first */
    public function activeFor(string $userId): Collection
    {
        if ($this->active && isset($this->memo[$userId])) {
            return $this->memo[$userId];
        }
        $sanctions = Sanction::where('user_id', $userId)->active()->orderByDesc('created_at')->orderByDesc('_id')->get();
        if ($this->active) {
            $this->memo[$userId] = $sanctions;
        }

        return $sanctions;
    }
}
