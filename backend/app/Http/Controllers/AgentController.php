<?php

namespace App\Http\Controllers;

use App\Actions\Agent\Web\ConfirmWebAgentPendingAction;
use App\Actions\Agent\Web\RunWebAgentAction;
use App\Actions\Agent\Web\WebAgentChannel;
use App\Actions\Agent\Web\WebAgentSession;
use App\Actions\Agent\Web\WebAgentUploads;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    /** Short lock around transcript surgery (rewind) and Confirm, so neither overwrites the other. */
    private const EDIT_LOCK_SECONDS = 10;

    public function __construct(
        private readonly RunWebAgentAction $run,
        private readonly ConfirmWebAgentPendingAction $confirm,
        private readonly WebAgentUploads $uploads,
    ) {}

    /**
     * POST /api/agent/chat — Server-Sent Events stream of one assistant turn.
     *
     * JSON, or multipart/form-data when receipt photos are attached: `images[]` (up to
     * WebAgentUploads::MAX_FILES, JPEG/PNG/WebP sniffed from the bytes, 8 MB each), the
     * message is then optional (it works like a Telegram photo caption) and `context`
     * may be a JSON string.
     *
     * `rewind` = replace_last (edit: `message` replaces the last user message) or regenerate (re-run
     * the last user message; `message` is ignored) drops the last turn first, see
     * WebAgentSession::rewindLastTurn. `turn_id` is the id of that user message; a stale one gets 409.
     * Photos can't be combined with a rewind.
     */
    public function chat(Request $request): StreamedResponse|JsonResponse
    {
        if (is_string($request->input('context'))) {
            $decoded = json_decode((string) $request->input('context'), true);
            $request->merge(['context' => is_array($decoded) ? $decoded : null]);
        }

        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:' . max(1, (int) config('agent.web_message_max_chars'))],
            'images' => ['nullable', 'array', 'max:' . WebAgentUploads::MAX_FILES],
            'images.*' => [
                'bail',
                'file',
                'max:' . intdiv(WebAgentUploads::MAX_BYTES, 1024),
                'mimetypes:' . implode(',', array_keys(WebAgentUploads::MIMES)),
            ],
            'conversation_id' => ['nullable', 'string', 'max:64'],
            'rewind' => ['nullable', 'string', 'in:' . WebAgentSession::REWIND_REPLACE . ',' . WebAgentSession::REWIND_REGENERATE],
            'turn_id' => ['required_with:rewind', 'nullable', 'string', 'max:64'],
            'context' => ['nullable', 'array'],
            'context.tab' => ['nullable', 'string', 'in:' . implode(',', WebAgentChannel::CONTEXT_TABS)],
            'context.period_type' => ['nullable', 'string', 'in:week,month,year'],
            'context.from' => ['nullable', 'date_format:Y-m-d'],
            'context.to' => ['nullable', 'date_format:Y-m-d'],
            'context.tag_ids' => ['nullable', 'array', 'max:50'],
            'context.tag_ids.*' => ['integer', 'min:1'],
            'context.untagged' => ['nullable', 'boolean'],
        ], [
            'images.max' => 'Send up to ' . WebAgentUploads::MAX_FILES . ' photos at a time.',
            'images.*.file' => 'That photo could not be uploaded. Try again.',
            'images.*.uploaded' => 'Each photo must be ' . intdiv(WebAgentUploads::MAX_BYTES, 1024 * 1024) . ' MB or smaller.',
            'images.*.max' => 'Each photo must be ' . intdiv(WebAgentUploads::MAX_BYTES, 1024 * 1024) . ' MB or smaller.',
            'images.*.mimetypes' => 'Only JPEG, PNG and WebP photos are supported.',
        ]);

        $user = $request->user();
        $context = $this->screenContext(is_array($data['context'] ?? null) ? $data['context'] : []);
        $message = trim((string) ($data['message'] ?? ''));
        $images = $request->file('images', []);
        $images = is_array($images) ? array_values($images) : [];
        $rewind = isset($data['rewind']) ? (string) $data['rewind'] : null;
        if ($rewind !== null && $images !== []) {
            return response()->json(['message' => "Photos can't be attached when editing or regenerating a message."], 422);
        }
        if ($message === '' && $images === [] && $rewind !== WebAgentSession::REWIND_REGENERATE) {
            return response()->json(['message' => 'The message field is required.'], 422);
        }

        // Private temp copies; the receipt flow attaches them on Confirm and deletes them.
        $files = [];
        try {
            foreach ($images as $image) {
                $files[] = $this->uploads->store($user, $image);
            }
        } catch (Throwable $e) {
            $this->uploads->delete($user, $files);
            throw $e;
        }

        // One running turn per user (double submit / several tabs).
        $lock = Cache::lock('web_agent_run:' . $user->id, self::RUN_LOCK_SECONDS);
        if (!$lock->get()) {
            $this->uploads->delete($user, $files);

            return response()->json(['message' => 'The assistant is still answering your previous message.'], 409);
        }

        $conversationId = isset($data['conversation_id']) ? (string) $data['conversation_id'] : null;
        $rewoundAsk = null;

        if ($rewind !== null) {
            $rewound = $this->rewind($user, $rewind, (string) $data['turn_id'], $conversationId);
            if (!$rewound['ok']) {
                $lock->release();

                return response()->json(['message' => $rewound['message'], 'reason' => $rewound['reason']], 409);
            }
            if ($rewind === WebAgentSession::REWIND_REGENERATE) {
                $message = $rewound['text'];
            }
            $rewoundAsk = $rewound['ask_context'];
        }

        return response()->stream(function () use ($user, $message, $conversationId, $context, $lock, $files, $rewoundAsk): void {
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

                $this->run->execute($user, $message, $conversationId, $emit(...), $context, $files, $rewoundAsk);
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
     * Drop the last turn for an edit / regenerate (under the edit lock, so a Confirm can't land in between).
     *
     * @return array{ok: true, text: string, ask_context: array{prior_question: string}|null}|array{ok: false, reason: string, message: string}
     */
    private function rewind(User $user, string $mode, string $turnId, ?string $conversationId): array
    {
        $session = new WebAgentSession($user);
        if ($conversationId === null || $conversationId !== $session->conversationId()) {
            return ['ok' => false, 'reason' => 'outdated', 'message' => 'This chat has changed. Send it as a new message instead.'];
        }

        try {
            $result = Cache::lock('web_agent_edit:' . $user->id, self::EDIT_LOCK_SECONDS)
                ->block(5, static fn (): array => $session->rewindLastTurn($turnId, $mode));
        } catch (LockTimeoutException) {
            return ['ok' => false, 'reason' => 'busy', 'message' => 'The assistant is busy. Try again in a moment.'];
        }

        if ($result['ok'] && is_array($result['dropped_pending'])) {
            $this->uploads->delete($user, WebAgentUploads::refsIn($result['dropped_pending']));
        }

        return $result;
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

        $user = $request->user();
        try {
            $result = Cache::lock('web_agent_edit:' . $user->id, self::EDIT_LOCK_SECONDS)
                ->block(5, fn (): array => $this->confirm->execute($user, (string) $data['pending_id'], (string) $data['action']));
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'The assistant is busy. Try again in a moment.'], 423);
        }

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
        $this->uploads->deleteAll($user);

        return response()->json([
            'ok' => true,
            'conversation_id' => $session->conversationId(),
            'quota' => $this->run->quotaPayload($user),
        ]);
    }
}
