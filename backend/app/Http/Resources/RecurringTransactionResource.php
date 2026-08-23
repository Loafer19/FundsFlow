<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'note' => $this->note,
            'frequency' => $this->frequency,
            'starts_at' => $this->starts_at->toDateString(),
            'next_run_at' => $this->next_run_at->toDateString(),
            'ends_at' => $this->ends_at?->toDateString(),
            'active' => $this->active,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
