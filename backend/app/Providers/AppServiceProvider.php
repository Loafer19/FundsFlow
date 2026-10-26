<?php

namespace App\Providers;

use App\Models\Budget;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\BudgetPolicy;
use App\Policies\RecurringTransactionPolicy;
use App\Policies\TagPolicy;
use App\Policies\TransactionPolicy;
use App\Support\ReportPrintToken;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }


    public function boot(): void
    {
        // TLS terminates at the proxy; generate https asset/route URLs when APP_URL is https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        JsonResource::withoutWrapping();

        Gate::policy(Tag::class, TagPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(Budget::class, BudgetPolicy::class);
        Gate::policy(RecurringTransaction::class, RecurringTransactionPolicy::class);

        // Scoped tokens are closed by default: a one-time `report:print` token authenticates
        // only GET report.print-data; full tokens (`*`: login, MCP) are unaffected.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && ReportPrintToken::allows($token, request())
                // Blocked in /admin: every existing token (web, Mini App, MCP) stops working.
                && !($token->tokenable instanceof User && $token->tokenable->isBlocked()),
        );
    }
}
