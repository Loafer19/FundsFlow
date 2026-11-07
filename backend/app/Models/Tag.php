<?php

namespace App\Models;

use App\Support\TagTreeCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tag extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'title',
        'emoji',
        'calc_balance',
    ];


    protected $casts = [
        'calc_balance' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Keep TagTree::for() in step when tags are added, moved, deleted or restored mid-request.
        $forget = static function (Tag $tag): void {
            if ($tag->user_id !== null) {
                app(TagTreeCache::class)->forget((int) $tag->user_id);
            }
        };

        static::saved($forget);
        static::deleted($forget);
        static::restored($forget);
        static::forceDeleted($forget);
    }

    /**
     * @return BelongsTo<User, Tag>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tag, Tag>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withTrashed();
    }

    /**
     * @return HasMany<Tag>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
