<?php

namespace App\Providers;

use App\Models\Tag;
use App\Models\Transaction;
use App\Policies\TagPolicy;
use App\Policies\TransactionPolicy;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        Gate::policy(Tag::class, TagPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);

    }
}
