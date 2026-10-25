<?php

namespace App\Http\Controllers;

use App\Actions\Agent\Web\ConfirmWebAgentPendingAction;
use App\Actions\Agent\Web\RunWebAgentAction;
use App\Actions\Agent\Web\WebAgentChannel;
use App\Actions\Agent\Web\WebAgentSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Web chat drawer for the FundsFlow agent (same tools/quota as the Telegram bot).
 */
class AgentController extends Controller
{
    private const RUN_LOCK_SECONDS = 180;

    public function __construct(
        private readonly RunWebAgentAction $run,
        private readonly ConfirmWebAgentPendingAction $confirm,
    ) {}

    /**
     * POST /api/agent/chat — Server-Sent Events stream of one assistant turn.
     */
    public function chat(Request $request): StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:' . max(1, (int) config('agent.web_message_max_chars'))],
            'conversation_id' => ['nullable', 'string', 'max:64'],
            'context' => ['nullable', 'array'],
            'context.tab' => ['nullable', 'string', 'in:' . implode(',', WebAgentChannel::CONTEXT_TABS)],
            'context.period_type' => ['nullable', 'string', 'in:week,month,year'],
            'context.from' => ['nullable', 'date_format:Y-m-d'],
            'context.to' => ['nullable', 'date_format:Y-m-d'],
            'context.tag_ids' => ['nullable', 'array', 'max:50'],
            'context.tag_ids.*' => ['integer', 'min:1'],
            'context.untagged' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $context = $this->screenContext(is_array($data['context'] ?? null) ? $data['context'] : []);
        $message = trim((string) $data['message']);
        if ($message === '') {
            return response()->json(['message' => 'The message field is required.'], 422);
        }

        // One running turn per user (double submit / several tabs).
        $lock = Cache::lock('web_agent_run:' . $user->id, self::RUN_LOCK_SECONDS);
        if (!$lock->get()) {
            return response()->json(['message' => 'The assistant is still answering your previous message.'], 409);
        }

        $conversationId = isset($data['conversation_id']) ? (string) $data['conversation_id'] : null;

        return response()->stream(function () use ($user, $message, $conversationId, $context, $lock): void {
            ignore_user_abort(true);
            @set_time_limit(self::RUN_LOCK_SECONDS);

            $emit = static function (string $event, array $payload): void {
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                echo 'event: ' . $event . "\n";
                echo 'data: ' . (is_string($json) ? $json : '{}') . "\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                // Padding comment pushes the headers + first bytes through any proxy buffer.
                echo ':' . str_repeat(' ', 2048) . "\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

                $this->run->execute($user, $message, $conversationId, $emit(...), $context);
            } catch (Throwable $e) {
                Log::error('AgentController: chat stream failed', ['message' => $e->getMessage()]);
                $emit('error', ['code' => 'server_error', 'message' => 'Something went wrong. Please try again.']);
                $emit('done', ['status' => 'error']);
            } finally {
                $lock->release();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Keep only the known, validated screen-context keys (tag ownership is checked when used).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function screenContext(array $raw): array
    {
        $ctx = [];
        if (isset($raw['tab'])) {
            $ctx['tab'] = (string) $raw['tab'];
        }
        if (isset($raw['period_type'])) {
            $ctx['period_type'] = (string) $raw['period_type'];
        }
        if (isset($raw['from'], $raw['to']) && (string) $raw['from'] <= (string) $raw['to']) {
            $ctx['from'] = (string) $raw['from'];
            $ctx['to'] = (string) $raw['to'];
        }
        if (is_array($raw['tag_ids'] ?? null) && $raw['tag_ids'] !== []) {
            $ctx['tag_ids'] = array_values(array_unique(array_map('intval', $raw['tag_ids'])));
        }
        if (filter_var($raw['untagged'] ?? false, FILTER_VALIDATE_BOOL)) {
            $ctx['untagged'] = true;
        }

        return $ctx;
    }

    /**
     * POST /api/agent/confirm — apply or cancel the staged change of a Confirm card.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pending_id' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'in:confirm,cancel'],
        ]);

        $result = $this->confirm->execute($request->user(), (string) $data['pending_id'], (string) $data['action']);

        return response()->json($result, $result['status'] === 'outdated' ? 409 : 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/agent/history — current conversation transcript + quota.
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = new WebAgentSession($user);
        $conversationId = $session->conversationId();
        $state = $session->state();

        return response()->json([
            'conversation_id' => $conversationId,
            'items' => $state['transcript'],
            'expires_at' => $state['expires_at'],
            'quota' => $this->run->quotaPayload($user),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * DELETE /api/agent/conversation — forget the conversation (pending previews included).
     */
    public function reset(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = new WebAgentSession($user);
        $session->clear();

        return response()->json([
            'ok' => true,
            'conversation_id' => $session->conversationId(),
            'quota' => $this->run->quotaPayload($user),
        ]);
    }
}
