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
            'amount' => $this->amount,
            'note' => $this->note,
            'transaction_type_id' => $this->transaction_type_id !== null ? (int) $this->transaction_type_id : null,
            'data' => $this->data ?: (object) [],
            'frequency' => $this->frequency,
            'starts_at' => $this->starts_at,
            'next_run_at' => $this->next_run_at,
            'ends_at' => $this->ends_at,
            'active' => $this->active,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
