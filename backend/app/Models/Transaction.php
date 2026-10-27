<?php

namespace App\Models;

use App\Enums\TransactionSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
     * Transactions dated on calendar days [from, to], both inclusive. `at` holds the
     * user's wall-clock time (no timezone conversion), so the days are the user's days;
     * the upper bound is the start of the day after `to`, so everything logged on `to`
     * counts (a bare 'Y-m-d' bound would mean midnight and drop the rest of that day).
     *
     * @param Builder<Transaction> $query
     */
    public function scopeOnDays(Builder $query, \DateTimeInterface|string $from, \DateTimeInterface|string $to): void
    {
        $fromDay = Carbon::parse($from)->toDateString();
        $nextDay = Carbon::parse($to)->addDay()->toDateString();

        $query->where('at', '>=', $fromDay . ' 00:00:00')->where('at', '<', $nextDay . ' 00:00:00');
    }

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
