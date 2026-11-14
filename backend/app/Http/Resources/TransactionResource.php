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
            'amount' => (float) $this->amount,
            'note' => $this->note,
            'source' => $this->source,
            'transaction_type_id' => $this->transaction_type_id !== null ? (int) $this->transaction_type_id : null,
            'data' => $this->data ?: (object) [],
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'attachments' => TransactionAttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
