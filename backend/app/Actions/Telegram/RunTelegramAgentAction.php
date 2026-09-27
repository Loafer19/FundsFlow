<?php

namespace App\Actions\Telegram;

use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Models\User;
use App\Support\TelegramAiQuota;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grok tool-calling agent loop for free-form Telegram finance questions.
 * Consumes 1 shared AI quota unit per user message that starts the agent (not per tool round).
 */
class RunTelegramAgentAction
{
    private const MAX_ROUNDS = 6;

    private const REQUEST_TIMEOUT = 60;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramChatSession $session,
        private readonly TelegramAiQuota $quota,
        private readonly TelegramAgentToolExecutor $tools,
    ) {}

    /**
     * @param array{prior_question?: string}|null $askContext When continuing after ask_user.
     * @return array{ok: bool, status: 'answered'|'ui_sent'|'ask_user'|'confirm_staged'|'unavailable'|'error', summary?: string}
     */
    public function execute(
        User $user,
        int|string $chatId,
        int|string $identity,
        string $text,
        ?array $askContext = null,
    ): array {
        $apiKey = (string) config('services.xai.api_key');
        $baseUrl = rtrim((string) config('services.xai.base_url', 'https://api.x.ai/v1'), '/');
        $model = (string) config('services.xai.text_model', 'grok-4');

        if ($apiKey === '') {
            return ['ok' => false, 'status' => 'unavailable', 'summary' => 'AI not configured.'];
        }

        $session = $this->session->get($identity, $chatId);
        $system = $this->systemPrompt($user, $session);
        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        if ($askContext !== null && ($askContext['prior_question'] ?? '') !== '') {
            $messages[] = [
                'role' => 'assistant',
                'content' => (string) $askContext['prior_question'],
            ];
            $messages[] = [
                'role' => 'user',
                'content' => 'User answer: ' . $text,
            ];
        } else {
            $messages[] = ['role' => 'user', 'content' => $text];
        }

        $tools = $this->tools->definitions();
        $quotaConsumed = false;
        $lastControl = null;

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout(self::REQUEST_TIMEOUT)
                    ->acceptJson()
                    ->post($baseUrl . '/chat/completions', [
                        'model' => $model,
                        'temperature' => 0.2,
                        'max_tokens' => 1200,
                        'messages' => $messages,
                        'tools' => $tools,
                        'tool_choice' => 'auto',
                    ]);
            } catch (Throwable $e) {
                Log::warning('RunTelegramAgentAction: exception', ['message' => $e->getMessage()]);

                return ['ok' => false, 'status' => 'unavailable', 'summary' => 'AI temporarily unavailable.'];
            }

            if (!$response->successful()) {
                Log::warning('RunTelegramAgentAction: xAI HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return ['ok' => false, 'status' => 'unavailable', 'summary' => 'AI temporarily unavailable.'];
            }

            if (!$quotaConsumed) {
                $this->quota->consume($user);
                $quotaConsumed = true;
            }

            $message = data_get($response->json(), 'choices.0.message');
            if (!is_array($message)) {
                return ['ok' => false, 'status' => 'error', 'summary' => 'Empty AI response.'];
            }

            $toolCalls = $message['tool_calls'] ?? null;
            $assistantContent = is_string($message['content'] ?? null) ? trim((string) $message['content']) : '';

            // Append assistant turn (with tool_calls when present).
            $assistantMessage = ['role' => 'assistant', 'content' => $message['content'] ?? null];
            if (is_array($toolCalls) && $toolCalls !== []) {
                $assistantMessage['tool_calls'] = $toolCalls;
            }
            $messages[] = $assistantMessage;

            if (!is_array($toolCalls) || $toolCalls === []) {
                if ($assistantContent !== '') {
                    $this->client->sendMessage($chatId, mb_substr($assistantContent, 0, 4000));
                    $this->session->setSummary($identity, $chatId, mb_substr($assistantContent, 0, 500));

                    return ['ok' => true, 'status' => 'answered', 'summary' => $assistantContent];
                }

                // No text and no tools — treat as soft failure.
                return ['ok' => false, 'status' => 'error', 'summary' => 'Empty AI response.'];
            }

            foreach ($toolCalls as $call) {
                if (!is_array($call)) {
                    continue;
                }

                $callId = (string) ($call['id'] ?? ('call_' . uniqid()));
                $name = (string) data_get($call, 'function.name', '');
                $rawArgs = data_get($call, 'function.arguments', '{}');
                $arguments = $this->decodeArguments($rawArgs);

                if ($name === '') {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'content' => json_encode(['error' => 'Missing tool name'], JSON_UNESCAPED_UNICODE),
                    ];
                    continue;
                }

                $executed = $this->tools->execute($user, $chatId, $identity, $name, $arguments);
                $lastControl = $executed['control'];

                $payload = [
                    'ok' => $executed['ok'],
                    'tool' => $name,
                    'result' => $executed['result'],
                ];
                if ($executed['control'] !== null) {
                    $payload['control'] = $executed['control'];
                    $payload['note'] = match ($executed['control']) {
                        'ui_sent' => 'Standard Telegram UI was already sent to the user. Do not repeat the full list; you may stop.',
                        'ask_user' => 'Clarifying question was sent. Stop and wait for the user reply.',
                        'confirm_staged' => 'A Confirm/Cancel preview was sent. Stop and wait; do not claim the change is saved.',
                        default => null,
                    };
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}',
                ];

                if (in_array($executed['control'], ['ask_user', 'confirm_staged', 'ui_sent'], true)) {
                    // Prefer stopping after UI/confirm/ask; still allow a short follow-up
                    // only when the model already had no parallel tools left (handled below).
                }
            }

            // If any tool requested a hard stop control, end after this tool batch
            // unless there is still useful assistant text already sent by tools.
            if (in_array($lastControl, ['ask_user', 'confirm_staged', 'ui_sent'], true)) {
                return [
                    'ok' => true,
                    'status' => (string) $lastControl,
                    'summary' => 'Agent stopped after ' . $lastControl,
                ];
            }
        }

        $this->client->sendMessage(
            $chatId,
            'I gathered some data but hit the step limit. Try a narrower question, or use the menu buttons.',
        );
        $this->session->setSummary($identity, $chatId, 'Agent hit max rounds.');

        return ['ok' => true, 'status' => 'answered', 'summary' => 'Max rounds reached.'];
    }

    public function quota(): TelegramAiQuota
    {
        return $this->quota;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function systemPrompt(User $user, array $session): string
    {
        $today = $user->todayDateString();
        $tz = $user->timezone();
        $domain = $session['domain'] ?? null;
        $summary = (string) ($session['last_bot_summary'] ?? '');
        $lastList = $session['last_list'] ?? [];
        $lastListJson = json_encode(array_slice(is_array($lastList) ? $lastList : [], 0, 30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lastListJson = is_string($lastListJson) ? $lastListJson : '[]';

        return <<<PROMPT
You are the FundsFlow personal finance assistant inside Telegram.
Today (user timezone {$tz}) is {$today}.
Session domain: {$domain}
Last bot summary: {$summary}
last_list (ids are authoritative; never invent ids): {$lastListJson}

Rules:
- Use tools for ANY numbers, balances, lists, budgets, or ids. Never invent amounts, tag ids, transaction ids, or budget figures.
- Prefer concise replies. Match the user's language for narrative advice (Ukrainian if they wrote Ukrainian; otherwise English). Keep button labels English via tools.
- Bot UI chrome (menus, Confirm/Cancel, slash help) is English — use the show_* / ask_user tools rather than inventing Telegram callback_data or raw keyboard JSON.
- For "show recent/budgets/tags/month/menu/help/web" prefer the matching show_* tool so cards stay consistent.
- For analysis questions (e.g. what do you think about my finances), call period_summary, list_budgets, list_recent_transactions (and more if needed), then answer with real figures.
- Mutations (create/update/delete money objects or tags) MUST go through create_*/update_*/delete_*/rename_tags tools which only stage a Confirm UI. Never claim a write succeeded before confirmation.
- If you need a missing detail, call ask_user (optional English suggestions). Then stop.
- Quick-add like "-350 groceries" is handled outside you; focus on questions, analysis, lists, and proposed mutations.
- Cap: tools already limit list sizes. Do not dump huge raw JSON to the user; summarize.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $rawArgs): array
    {
        if (is_array($rawArgs)) {
            return $rawArgs;
        }
        if (!is_string($rawArgs) || trim($rawArgs) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawArgs, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
