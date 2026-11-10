<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportTemplate extends Model
{
    protected $fillable = ['user_id', 'name', 'header_signature', 'mapping', 'category_map'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'category_map' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function signature(array $headerRow): string
    {
        $normalized = array_map(static function ($cell) {
            return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $cell) ?? (string) $cell));
        }, $headerRow);

        return hash('sha256', implode('|', $normalized));
    }
}
