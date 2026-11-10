<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ImportBatch extends Model
{
    public const STATUS_PREVIEWING = 'previewing';
    public const STATUS_COMMITTING = 'committing';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_UNDONE = 'undone';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'uuid', 'source', 'status', 'filename', 'format', 'file_hash',
        'header_signature', 'mapping', 'options', 'category_map', 'created_tag_ids', 'draft', 'notes', 'ai_used',
        'total_rows', 'new_count', 'duplicate_count', 'transfer_count', 'skipped_count',
        'needs_tag_count', 'error_count', 'income_total', 'expense_total',
        'period_from', 'period_to', 'progress', 'error_message', 'committed_at', 'undone_at',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'options' => 'array',
            'category_map' => 'array',
            'created_tag_ids' => 'array',
            'draft' => 'array',
            'notes' => 'array',
            'ai_used' => 'boolean',
            'income_total' => 'decimal:2',
            'expense_total' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
            'committed_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            if (!$batch->uuid) {
                $batch->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function previewUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/import/' . $this->uuid;
    }

    public function isMutable(): bool
    {
        return in_array($this->status, [self::STATUS_PREVIEWING, self::STATUS_FAILED], true);
    }
}
