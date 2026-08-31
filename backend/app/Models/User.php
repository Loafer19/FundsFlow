<?php

namespace App\Models;

use Database\Seeders\DefaultTagsSeeder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const AUTH_PROVIDERS = [
        'google',
        'github',
    ];

    public const DEFAULT_PREFERENCES = [
        'moneyFormat' => 'uk-UA',
        'dateFormat' => 'DD.MM.YYYY',
        'decimals' => true,
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
     * @return array{moneyFormat: string, dateFormat: string, decimals: bool}
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
        ];
    }
}
