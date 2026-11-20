<?php

namespace App\Models;

use App\Enums\RecurringFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RecurringTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'note',
        'transaction_type_id',
        'data',
        'frequency',
        'starts_at',
        'next_run_at',
        'ends_at',
        'active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'frequency' => RecurringFrequency::class,
        'starts_at' => 'date',
        'next_run_at' => 'date',
        'ends_at' => 'date',
        'active' => 'boolean',
        'data' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTrashed();
    }

    /**
     * @return BelongsTo<TransactionType, $this>
     */
    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class)->withTrashed();
    }
}
