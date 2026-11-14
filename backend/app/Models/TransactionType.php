<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A transaction type ("Fuel") with custom fields; see App\Support\TransactionFields for the schema.
 * Deleting archives it (soft delete): its transactions keep the type and their values.
 */
class TransactionType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'emoji',
        'color',
        'fields',
        'default_tag_ids',
    ];

    protected $casts = [
        'fields' => 'array',
        'default_tag_ids' => 'array',
    ];

    /**
     * @return BelongsTo<User, TransactionType>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Transaction, TransactionType>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visibleFields(): array
    {
        return array_values(array_filter(
            is_array($this->fields) ? $this->fields : [],
            static fn ($field): bool => is_array($field) && !($field['hidden'] ?? false),
        ));
    }

    /**
     * The visible computed field that writes the transaction amount, if any.
     *
     * @return null|array<string, mixed>
     */
    public function fillAmountField(): ?array
    {
        foreach ($this->visibleFields() as $field) {
            if (($field['type'] ?? null) === 'computed' && ($field['fill_amount'] ?? false)) {
                return $field;
            }
        }

        return null;
    }

    /** "⛽ Fuel" */
    public function label(): string
    {
        return trim(($this->emoji ?? '') . ' ' . $this->name);
    }

    /**
     * @return list<int>
     */
    public function defaultTagIds(): array
    {
        return array_values(array_map('intval', is_array($this->default_tag_ids) ? $this->default_tag_ids : []));
    }
}
