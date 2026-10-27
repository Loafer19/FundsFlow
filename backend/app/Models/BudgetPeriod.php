<?php

namespace App\Models;

use App\Enums\BudgetLength;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

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

    /**
     * [first day, first day of the next bucket] of the limit bucket containing $date,
     * on its budget's grid (budgets.anchor_date; calendar weeks/months/years when null).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bucketBounds(CarbonInterface $date): array
    {
        return $this->length->bucketBounds($date, $this->budget?->anchor_date);
    }

    /**
     * All versions of this period's budget (this one included), for bucket maths.
     *
     * @return Collection<int, BudgetPeriod>
     */
    public function siblings(): Collection
    {
        $budget = $this->budget;

        if (!$budget) {
            return collect([$this]);
        }

        $periods = $budget->periods;
        $periods->each(fn (BudgetPeriod $p) => $p->relationLoaded('budget') || $p->setRelation('budget', $budget));

        return $periods->contains(fn (BudgetPeriod $p) => $p->id === $this->id) ? $periods : $periods->toBase()->concat([$this]);
    }

    public function isActive(): bool
    {
        return $this->ends_at === null;
    }
}
