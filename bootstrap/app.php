<?php

use App\ApiTokens\TokenException;
use App\Exceptions\Handler;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CheckUserSanction;
use App\Http\Middleware\EnforceAccountDeletion;
use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withSingletons([ExceptionHandler::class => Handler::class])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'verified' => EnsureEmailIsVerified::class,
            'sanction' => CheckUserSanction::class,
        ]);

        $middleware->validateCsrfTokens(except: ['stripe/webhook']);

        $middleware->appendToGroup('web', EnforceAccountDeletion::class);
        $middleware->appendToGroup('web', CheckUserSanction::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/v1', 'api/v1/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }
            [$code, $status] = match (true) {
                $e instanceof TokenException => [$e->errorCode, $e->status],
                $e instanceof ValidationException => ['validation_error', 422],
                $e instanceof AuthorizationException => ['forbidden', 403],
                $e instanceof AuthenticationException => ['unauthenticated', 401],
                $e instanceof ModelNotFoundException => ['not_found', 404],
                $e instanceof HttpExceptionInterface => [match ($e->getStatusCode()) {
                    401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
                    405 => 'method_not_allowed', 429 => 'rate_limited', default => 'server_error',
                }, $e->getStatusCode()],
                default => ['server_error', 500],
            };

            return response()->json(['error' => ['code' => $code]], $status, $e instanceof HttpExceptionInterface ? $e->getHeaders() : [])->header('Cache-Control', 'no-store');
        });
        $exceptions->report(function (TokenException $e) {
            if (app()->bound('request') && app('request') instanceof Request
                && app('request')->is('api/v1', 'api/v1/*')) {
                return false; // Store failures already emitted a sanitized warning.
            }
        });
    })->create();
