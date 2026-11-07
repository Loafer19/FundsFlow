<?php

namespace App\Http\Resources;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $last = $this->relationLoaded('latestMessage') ? $this->latestMessage : null;

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'status' => $this->status,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // The list shows "Replied" when support wrote last.
            'last_author' => $this->when($this->relationLoaded('latestMessage'), fn () => $last?->author ?? SupportTicketMessage::AUTHOR_USER),
            'body' => $this->when($this->relationLoaded('messages'), $this->body),
            'messages' => $this->when($this->relationLoaded('messages'), fn () => $this->messages->map(fn (SupportTicketMessage $message) => [
                'id' => $message->id,
                'author' => $message->author,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
