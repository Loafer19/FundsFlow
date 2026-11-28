<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'emoji' => $this->emoji,
            'calc_balance' => $this->calc_balance,
            'archived_at' => $this->deleted_at?->toIso8601String(),
            // Only on the tag list (withCount); nested copies on transactions/budgets omit it.
            'transactions_count' => $this->whenCounted('transactions'),
        ];
    }
}
