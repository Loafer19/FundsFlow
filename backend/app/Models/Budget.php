<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
    ];

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
