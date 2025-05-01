<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        // 'id',
        // 'user_id',
        'parent_id',
        'title',
        'emoji',
        'calc_balance',
        // 'created_at',
        // 'updated_at',
    ];

    protected $casts = [
        'calc_balance' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, Transaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
