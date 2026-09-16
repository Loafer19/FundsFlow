<?php

namespace App\Models;

use App\Enums\TransactionSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'at',
        'amount',
        'note',
        'source',
    ];


    protected $casts = [
        'at' => 'datetime',
        'amount' => 'decimal:2',
        'source' => TransactionSource::class,
    ];

    /**
     * @return BelongsTo<User, Transaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Tag, Transaction>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTrashed();
    }

    /**
     * @return HasMany<TransactionAttachment, Transaction>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TransactionAttachment::class);
    }
}
