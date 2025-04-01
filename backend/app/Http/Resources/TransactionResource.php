<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'at' => $this->at,
            'amount' => $this->amount,
            'note' => $this->note,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
