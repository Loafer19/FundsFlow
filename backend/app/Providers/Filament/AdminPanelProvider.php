<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Admin panel at /admin (api.fundsflow.fun/admin). The only part of the backend with sessions,
 * cookies and CSRF; routes/api.php stays on Sanctum bearer tokens. Access: users.is_admin
 * (php artisan admin:grant) + an authenticator app (TOTP), required on first sign-in.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->brandName('FundsFlow admin')
                    ->recoverable(),
            ], isRequired: true)
            ->brandName('FundsFlow')
            ->brandLogo(fn () => new HtmlString(
                '<span style="display:inline-flex;align-items:center;gap:.5rem;font-weight:700;font-size:1.15rem">'
                . '<img src="' . '/images/fundsflow-logo.png' . '" alt="" style="height:2rem;width:2rem;border-radius:.5rem">'
                . 'FundsFlow</span>'
            ))
            ->brandLogoHeight('2rem')
            ->favicon('/images/fundsflow-favicon.png')
            ->colors([
                'primary' => Color::Amber,
                'gray' => Color::Stone,
            ])
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Dark)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            // No `notifications` table: flash notifications only.
            ->databaseNotifications(false)
            // UX: collapsible sidebar (icon rail) on desktop, SPA navigation (same-origin
            // fetches only, fine under the /admin CSP), "unsaved changes" prompt, full-width
            // tables, and global search (users by email/name) on Ctrl+K / Cmd+K.
            ->sidebarCollapsibleOnDesktop()
            ->spa()
            ->unsavedChangesAlerts()
            ->maxContentWidth(Width::Full)
            ->globalSearch()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->globalSearchDebounce('300ms')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
