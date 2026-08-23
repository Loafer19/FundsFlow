<?php

namespace App\Models;

use App\Enums\BudgetLength;
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
}
