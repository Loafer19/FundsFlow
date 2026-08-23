<?php

namespace App\Models;

use App\Enums\BudgetLength;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BudgetPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'length',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'length' => BudgetLength::class,
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->ends_at === null;
    }

    /**
     * Spending window: for the active period, the current week/month/year
     * bucket so far; for a closed period, its full historical range.
     */
    public function windowStart(): Carbon
    {
        if (! $this->isActive()) {
            return $this->starts_at->copy();
        }

        $boundary = match ($this->length) {
            BudgetLength::Week => now()->startOfWeek(),
            BudgetLength::Month => now()->startOfMonth(),
            BudgetLength::Year => now()->startOfYear(),
        };

        return $boundary->greaterThan($this->starts_at) ? $boundary : $this->starts_at->copy();
    }

    public function windowEnd(): Carbon
    {
        return $this->isActive() ? now() : $this->ends_at->copy();
    }

    public function spent(): float
    {
        $tagIds = $this->tags->pluck('id');

        if ($tagIds->isEmpty()) {
            return 0.0;
        }

        $sum = Transaction::query()
            ->where('user_id', $this->budget->user_id)
            ->whereBetween('at', [$this->windowStart(), $this->windowEnd()])
            ->where('amount', '<', 0)
            ->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds))
            ->sum('amount');

        return (float) $sum * -1;
    }
}
