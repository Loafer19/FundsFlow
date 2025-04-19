<?php

use App\Http\Middleware\AlwaysJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(AlwaysJson::class);

        $middleware
            ->statefulApi()
            ->throttleApi('60,1');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            return response()->json([
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        });
    })
    ->withProviders([
        App\Providers\AppServiceProvider::class,
        SanctumServiceProvider::class,
        SocialiteServiceProvider::class,
    ])
    ->create();
