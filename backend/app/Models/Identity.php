<?php

namespace App\Models;

use App\Support\UserAnalytics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Identity extends Model
{
    protected $fillable = [
        'provider',
        'external_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::created(function (Identity $identity): void {
            if ($identity->provider !== 'telegram') {
                return;
            }

            $user = $identity->user;

            if ($user) {
                UserAnalytics::telegramLinked($user);
            }
        });
    }
}
