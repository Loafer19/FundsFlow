<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            // Goals keep a placeholder length in the column; it has no meaning for them.
            'length' => $this->relationLoaded('budget') && $this->budget?->isGoal() ? null : $this->length,
            'starts_at' => $this->starts_at->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'active' => $this->isActive(),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
