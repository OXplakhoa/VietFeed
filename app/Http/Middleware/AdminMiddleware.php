<?php

namespace App\Http\Middleware;

use App\Authorization\Permissions;
use App\Authorization\UserAuthorization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Unauthorized.');
        }

        // These requests may fail the route Gate before the account policy runs.
        $target = $request->route('user');
        if (! $user->hasPermission(Permissions::USERS) && $target instanceof User
            && $request->routeIs('admin.users.edit', 'admin.users.update', 'admin.users.destroy')
            && ($target->isAdmin() || ($request->routeIs('admin.users.update') && $request->hasAny(['role', 'permissions'])))) {
            app(UserAuthorization::class)->recordPrivilegeRefusal($user, $target);
        }

        return $next($request);
    }
}
