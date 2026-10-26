<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AlwaysJson
{
    /**
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!self::isPanelRequest($request)) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }

    /**
     * The Filament admin panel and its Livewire endpoints: HTML, sessions and CSRF
     * instead of the API's JSON + Sanctum tokens.
     */
    public static function isPanelRequest(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        return $path === 'admin'
            || str_starts_with($path, 'admin/')
            || str_starts_with($path, 'livewire');
    }
}
