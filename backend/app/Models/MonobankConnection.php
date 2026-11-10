<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonobankConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_ERROR = 'error';

    protected $hidden = [
        'token',
    ];

    protected $fillable = [
        'token',
        'webhook_uuid',
        'status',
        'notify',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'notify' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<MonobankAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(MonobankAccount::class);
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/webhooks/monobank/' . $this->webhook_uuid;
    }
}
