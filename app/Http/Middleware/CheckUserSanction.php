<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserSanction
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        // Permanent ban: everything redirects to the banned page except it, logout and appeals.
        if ($user->isPermanentlyBanned()) {
            if (! $request->routeIs('sanctions.banned', 'logout', 'sanctions.appeal')) {
                return redirect()->route('sanctions.banned');
            }

            return $next($request);
        }

        return $next($request);
    }
}
