<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the user's own Analytics cards (kind stat|chart|pie, size S|M|L, plain integer position).
 * The config shape lives in App\Support\DashboardCardConfig.
 */
class DashboardCard extends Model
{
    protected $fillable = [
        'kind',
        'config',
        'size',
        'position',
    ];

    protected $casts = [
        'config' => 'array',
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<User, DashboardCard>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
