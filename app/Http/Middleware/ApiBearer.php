<?php

namespace App\Http\Middleware;

use App\ApiTokens\ApiTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = app(ApiTokenService::class)->authenticate($request->bearerToken() ?? '');
        $request->setUserResolver(fn () => $identity['user']);
        $request->attributes->set('api_token_family', $identity['family']);

        return $next($request);
    }
}
