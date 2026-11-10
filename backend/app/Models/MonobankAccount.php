<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonobankAccount extends Model
{
    protected $fillable = [
        'mono_account_id',
        'currency_code',
        'type',
        'masked_pan',
        'iban',
        'title',
        'balance',
        'goal_amount',
        'enabled',
        'tag_id',
    ];

    protected function casts(): array
    {
        return [
            'currency_code' => 'integer',
            'balance' => 'decimal:2',
            'goal_amount' => 'decimal:2',
            'enabled' => 'boolean',
            'tag_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MonobankConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(MonobankConnection::class, 'monobank_connection_id');
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function isJar(): bool
    {
        return $this->type === 'jar';
    }

    public function isUah(): bool
    {
        return (int) $this->currency_code === (int) config('monobank.uah_currency_code', 980);
    }

    public static function fromMinor(mixed $amount): float
    {
        return round(((int) $amount) / 100, 2);
    }

    public function currencyCode(): string
    {
        return match ((int) $this->currency_code) {
            980 => 'UAH',
            840 => 'USD',
            978 => 'EUR',
            826 => 'GBP',
            985 => 'PLN',
            default => (string) $this->currency_code,
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'black' => 'Black',
            'white' => 'White',
            'platinum' => 'Platinum',
            'iron' => 'Iron',
            'fop' => 'FOP',
            'yellow' => 'Yellow',
            'eAid' => 'eAid',
            'jar' => 'Jar',
            default => ucfirst((string) $this->type),
        };
    }

    public function label(): string
    {
        if ($this->type === 'jar' && $this->title) {
            return 'Jar · ' . $this->title;
        }

        $pan = (string) $this->masked_pan;
        $last4 = $pan !== '' ? substr($pan, -4) : '';
        $type = $this->typeLabel();

        if ($last4 !== '' && ctype_digit($last4)) {
            return $type . ' · ••' . $last4;
        }

        if ($this->iban) {
            return $type . ' · ' . substr((string) $this->iban, -4);
        }

        return $type;
    }
}
