<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'budget_period_id',
        'threshold',
        'bucket_start',
    ];

    protected $casts = [
        'bucket_start' => 'date',
        'sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<BudgetPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class, 'budget_period_id');
    }
}
