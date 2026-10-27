<?php

namespace App\Models;

use App\Enums\BudgetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A spending limit (type limit, per week/month/year bucket) or a savings goal
 * (type goal: target = period amount, contributions = transactions with the
 * period's tags since the goal started; see CalculateGoalProgressAction).
 */
class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'type',
        'target_date',
        'start_amount',
    ];

    protected $attributes = [
        'type' => 'limit',
    ];

    protected $casts = [
        'type' => BudgetType::class,
        'target_date' => 'date',
        'start_amount' => 'decimal:2',
    ];

    public function isGoal(): bool
    {
        return $this->type === BudgetType::Goal;
    }

    /**
     * First day a goal counts contributions from: its earliest period's starts_at.
     */
    public function goalStartDate(): ?string
    {
        $first = $this->relationLoaded('periods')
            ? $this->periods->sortBy(fn (BudgetPeriod $p) => $p->starts_at?->toDateString())->first()
            : $this->periods()->reorder('starts_at')->first();

        return $first?->starts_at?->toDateString();
    }

    /**
     * Move a goal's start: the earliest period begins on $date, and any later
     * period that would now begin before it is clamped, so the earliest
     * starts_at (what progress counts from) is exactly $date.
     */
    public function moveGoalStart(string $date): void
    {
        $periods = $this->periods()->reorder('starts_at')->get();
        $first = $periods->shift();

        if (!$first) {
            return;
        }

        $first->update([
            'starts_at' => $date,
            'ends_at' => $first->ends_at && $first->ends_at->toDateString() < $date ? $date : $first->ends_at,
        ]);

        foreach ($periods as $period) {
            if ($period->starts_at->toDateString() < $date) {
                $period->update([
                    'starts_at' => $date,
                    'ends_at' => $period->ends_at && $period->ends_at->toDateString() < $date ? $date : $period->ends_at,
                ]);
            }
        }

        $this->unsetRelation('periods');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BudgetPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class)->orderByDesc('starts_at');
    }

    /**
     * @return HasOne<BudgetPeriod, $this>
     */
    public function currentPeriod(): HasOne
    {
        return $this->hasOne(BudgetPeriod::class)->whereNull('ends_at');
    }
}
