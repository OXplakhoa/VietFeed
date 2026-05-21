<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || $request->user()->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message'        => 'Vui lòng xác minh email để sử dụng tính năng này.',
                'verify_required' => true,
            ], 403);
        }

        return redirect()->back()->with('verify_email_required', true);
    }
}
