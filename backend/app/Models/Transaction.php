<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasUlids;

    protected $fillable = [
        // ulid
        // 'user_id',
        'at',
        'amount',
        'note',
        // created_at
        // updated_at
    ];

    protected $casts = [
        'at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, Transaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
