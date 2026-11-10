<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_TRANSFER = 'transfer';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_NEEDS_TAG = 'needs_tag';
    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'import_batch_id', 'line', 'status', 'at', 'amount', 'note', 'currency',
        'raw', 'category', 'tag_ids', 'suggested_tag_ids', 'new_tag_titles', 'manual', 'skip_reason',
        'error', 'matched_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'at' => 'date',
            'amount' => 'decimal:2',
            'raw' => 'array',
            'tag_ids' => 'array',
            'suggested_tag_ids' => 'array',
            'new_tag_titles' => 'array',
            'manual' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function isImportable(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_NEEDS_TAG], true)
            && $this->at && $this->amount !== null && (float) $this->amount != 0.0;
    }
}
