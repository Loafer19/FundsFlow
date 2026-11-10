<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonobankTagRule extends Model
{
    protected $fillable = [
        'user_id',
        'merchant_key',
        'mcc',
        'tag_id',
    ];

    protected function casts(): array
    {
        return [
            'mcc' => 'integer',
            'tag_id' => 'integer',
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
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
