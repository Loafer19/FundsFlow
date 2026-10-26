<?php

use App\Http\Middleware\AlwaysJson;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\AppServiceProvider::class,
        SanctumServiceProvider::class,
        SocialiteServiceProvider::class,
        AdminPanelProvider::class,
    ])
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind Traefik (Dokploy) and Caddy: trust X-Forwarded-* so URLs/redirects use https.
        $middleware->trustProxies(at: '*');

        $middleware->append(AlwaysJson::class);

        $middleware->throttleApi('180,1');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The API is JSON-only; the Filament panel (/admin, /livewire*) gets normal HTML
        // pages, login redirects and the 419 page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => !AlwaysJson::isPanelRequest($request));

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (AlwaysJson::isPanelRequest($request)) {
                return null;
            }

            return response()->json(['error' => 'Unauthorized'], 401);
        });
    })
    ->create();
