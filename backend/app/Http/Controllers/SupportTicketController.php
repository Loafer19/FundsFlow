<?php

namespace App\Http\Controllers;

use App\Actions\Support\CreateSupportTicketAction;
use App\Actions\Support\ReplyToSupportTicketAction;
use App\Actions\Support\SupportRateLimitException;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Feedback / Report a problem" requests of the signed-in user (Settings → Support).
 */
class SupportTicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = $request->user()->supportTickets()
            ->with('latestMessage')
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return SupportTicketResource::collection($tickets);
    }

    public function store(Request $request, CreateSupportTicketAction $create): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:' . CreateSupportTicketAction::SUBJECT_MAX,
            'body' => 'required|string|max:' . CreateSupportTicketAction::BODY_MAX,
            'app_version' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z.+-]+$/'],
        ]);

        try {
            $ticket = $create->execute(
                $request->user(),
                $data['body'],
                $data['subject'],
                SupportTicket::SOURCE_WEB,
                $data['app_version'] ?? null,
                $request->userAgent(),
            );
        } catch (SupportRateLimitException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        return (new SupportTicketResource($ticket->load('messages')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $ticket): SupportTicketResource
    {
        return new SupportTicketResource($this->find($request, $ticket)->load('messages'));
    }

    public function reply(Request $request, int $ticket, ReplyToSupportTicketAction $reply): SupportTicketResource
    {
        $data = $request->validate([
            'body' => 'required|string|max:' . CreateSupportTicketAction::BODY_MAX,
        ]);

        $model = $this->find($request, $ticket);
        $reply->fromUser($model, $data['body']);

        return new SupportTicketResource($model->refresh()->load('messages'));
    }

    private function find(Request $request, int $id): SupportTicket
    {
        return $request->user()->supportTickets()->findOrFail($id);
    }
}
