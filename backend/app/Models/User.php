<?php

namespace App\Models;

use Database\Seeders\DefaultTagsSeeder;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasApiTokens, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    public const AUTH_PROVIDERS = [
        'google',
        'github',
    ];

    public const DEFAULT_PREFERENCES = [
        'moneyFormat' => 'uk-UA',
        'dateFormat' => 'DD.MM.YYYY',
        'decimals' => true,
        'timezone' => 'Europe/Kyiv',
    ];


    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'preferences',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
        // Admin-only fields stay out of /auth/me and other API payloads.
        'is_admin',
        'ai_daily_limit',
        'blocked_at',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
        'created_at',
        'updated_at',
    ];

    protected static function booted(): void
    {
        static::created(function (User $user) {
            (new DefaultTagsSeeder)->run($user);
        });
    }

    /**
     * Admin panel (/admin): admins only, and never a blocked account.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin && !$this->isBlocked();
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * @return array{moneyFormat: string, dateFormat: string, decimals: bool, timezone: string}
     */
    public function resolvedPreferences(): array
    {
        return array_merge(self::DEFAULT_PREFERENCES, $this->preferences ?? []);
    }

    public function moneyFormat(): string
    {
        return (string) ($this->resolvedPreferences()['moneyFormat'] ?? self::DEFAULT_PREFERENCES['moneyFormat']);
    }

    public function dateFormat(): string
    {
        return (string) ($this->resolvedPreferences()['dateFormat'] ?? self::DEFAULT_PREFERENCES['dateFormat']);
    }

    public function showDecimals(): bool
    {
        return (bool) ($this->resolvedPreferences()['decimals'] ?? self::DEFAULT_PREFERENCES['decimals']);
    }

    public function timezone(): string
    {
        $timezone = (string) ($this->resolvedPreferences()['timezone'] ?? self::DEFAULT_PREFERENCES['timezone']);

        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            return self::DEFAULT_PREFERENCES['timezone'];
        }

        return $timezone;
    }

    public function nowInTimezone(): \Carbon\Carbon
    {
        return now($this->timezone());
    }

    public function todayDateString(): string
    {
        return $this->nowInTimezone()->toDateString();
    }


    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<Identity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class);
    }

    /**
     * @return HasMany<TransactionAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TransactionAttachment::class);
    }

    /**
     * @return HasMany<Budget, $this>
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * @return HasMany<RecurringTransaction, $this>
     */
    public function recurringTransactions(): HasMany
    {
        return $this->hasMany(RecurringTransaction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'is_admin' => 'boolean',
            'ai_daily_limit' => 'integer',
            'blocked_at' => 'datetime',
        ];
    }
}
