<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $headers = $e->getHeaders();
                $retryAfter = (int) ($headers['Retry-After'] ?? 60);
                $minutes = max(1, (int) ceil($retryAfter / 60));

                $lang = str_starts_with(strtolower((string) $request->header('Accept-Language', $request->query('lang', 'ar'))), 'en') ? 'en' : 'ar';

                $message = $lang === 'en'
                    ? "Too many requests. Please wait {$minutes} minute(s) before trying again."
                    : "لقد تجاوزت عدد المحاولات المسموح بها. يرجى الانتظار {$minutes} دقيقة والمحاولة مرة أخرى.";

                return response()->json([
                    'status' => false,
                    'message' => $message,
                    'retry_after_seconds' => $retryAfter,
                    'retry_after_minutes' => $minutes,
                ], 429, $headers);
            }
        });
    })->create();
