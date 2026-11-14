<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'emoji' => $this->emoji,
            'color' => $this->color,
            // Hidden (removed) fields too: their values stay on old transactions.
            'fields' => array_values($this->fields ?? []),
            'default_tag_ids' => array_values(array_map('intval', $this->default_tag_ids ?? [])),
            'transactions_count' => $this->when(isset($this->transactions_count), fn () => (int) $this->transactions_count),
            'archived_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
