<?php

namespace App\Actions\Agent;

use App\Models\User;
use App\Support\TelegramAiQuota;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transport-agnostic Grok tool-calling loop (Telegram, web chat).
 * Consumes 1 shared AI quota unit per user message that starts the agent (not per tool round).
 * Output (answers, UI cards, confirm previews) goes through the AgentChannel.
 */
class RunAgentAction
{
    /** Controls returned by tools that end the run after the current batch. */
    public const STOP_CONTROLS = ['ask_user', 'confirm_staged', 'ui_sent'];

    public function __construct(
        private readonly TelegramAiQuota $quota,
    ) {}

    /**
     * @param array{prior_question?: string}|null $askContext When continuing after ask_user.
     * @return array{ok: bool, status: 'answered'|'ui_sent'|'ask_user'|'confirm_staged'|'unavailable'|'error', summary?: string}
     */
    public function execute(User $user, AgentChannel $channel, string $text, ?array $askContext = null): array
    {
        $apiKey = (string) config('services.xai.api_key');
        $baseUrl = rtrim((string) config('services.xai.base_url', 'https://api.x.ai/v1'), '/');
        $model = (string) config('services.xai.text_model', 'grok-4');

        if ($apiKey === '') {
            return ['ok' => false, 'status' => 'unavailable', 'summary' => 'AI not configured.'];
        }

        $messages = [
            ['role' => 'system', 'content' => $channel->systemPrompt($user)],
        ];

        foreach ($channel->historyMessages() as $turn) {
            $messages[] = $turn;
        }

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

        $tools = $channel->toolDefinitions();
        $stagingTools = $channel->stagingTools();
        $quotaConsumed = false;

        $maxRounds = (int) config('telegram.agent_max_rounds');
        for ($round = 0; $round < $maxRounds; $round++) {
            $payload = [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => 1200,
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
            ];

            $reply = $channel->streams()
                ? $this->requestStreaming($apiKey, $baseUrl, $payload, $channel)
                : $this->request($apiKey, $baseUrl, $payload);

            if ($reply['status'] === 'unavailable') {
                return ['ok' => false, 'status' => 'unavailable', 'summary' => 'AI temporarily unavailable.'];
            }

            if (!$quotaConsumed) {
                $this->quota->consume($user);
                $quotaConsumed = true;
            }

            $message = $reply['message'];
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
                    $channel->onAnswer($assistantContent);

                    return ['ok' => true, 'status' => 'answered', 'summary' => $assistantContent];
                }

                // No text and no tools — treat as soft failure.
                return ['ok' => false, 'status' => 'error', 'summary' => 'Empty AI response.'];
            }

            $lastControl = null;
            $stagedInBatch = false;

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

                // One pending confirmation per conversation: a second staged change in the
                // same batch would replace the first preview behind the user's back.
                if ($stagedInBatch && in_array($name, $stagingTools, true)) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $callId,
                        'content' => json_encode([
                            'ok' => false,
                            'tool' => $name,
                            'result' => ['error' => 'Not staged: only one change can wait for confirmation at a time. Ask the user to confirm the first one, then propose the next.'],
                        ], JSON_UNESCAPED_UNICODE),
                    ];
                    continue;
                }

                $channel->onToolCall($name, $arguments);
                $executed = $channel->executeTool($user, $name, $arguments);
                // Any stop control in the batch ends the run, not only the last call's.
                $lastControl = $executed['control'] ?? $lastControl;
                $stagedInBatch = $stagedInBatch || $executed['control'] === 'confirm_staged';

                $toolPayload = [
                    'ok' => $executed['ok'],
                    'tool' => $name,
                    'result' => $executed['result'],
                ];
                if ($executed['control'] !== null) {
                    $toolPayload['control'] = $executed['control'];
                    $toolPayload['note'] = $channel->controlNote($executed['control']);
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => json_encode($toolPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}',
                ];
            }

            // If any tool requested a hard stop control, end after this tool batch.
            if (in_array($lastControl, self::STOP_CONTROLS, true)) {
                return [
                    'ok' => true,
                    'status' => (string) $lastControl,
                    'summary' => 'Agent stopped after ' . $lastControl,
                ];
            }
        }

        $channel->onMaxRounds();

        return ['ok' => true, 'status' => 'answered', 'summary' => 'Max rounds reached.'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: 'ok'|'unavailable', message: mixed}
     */
    private function request(string $apiKey, string $baseUrl, array $payload): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('telegram.timeouts.xai_chat'))
                ->acceptJson()
                ->post($baseUrl . '/chat/completions', $payload);
        } catch (Throwable $e) {
            Log::warning('RunAgentAction: exception', ['message' => $e->getMessage()]);

            return ['status' => 'unavailable', 'message' => null];
        }

        if (!$response->successful()) {
            Log::warning('RunAgentAction: xAI HTTP error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return ['status' => 'unavailable', 'message' => null];
        }

        return ['status' => 'ok', 'message' => data_get($response->json(), 'choices.0.message')];
    }

    /**
     * Same request with `stream: true`; text chunks go to the channel as they arrive and
     * tool call fragments are merged by index into the same shape as a non-streamed message.
     *
     * @param array<string, mixed> $payload
     * @return array{status: 'ok'|'unavailable', message: mixed}
     */
    private function requestStreaming(string $apiKey, string $baseUrl, array $payload, AgentChannel $channel): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('telegram.timeouts.xai_chat'))
                ->withOptions(['stream' => true])
                ->accept('text/event-stream')
                ->post($baseUrl . '/chat/completions', $payload + ['stream' => true]);
        } catch (Throwable $e) {
            Log::warning('RunAgentAction: stream exception', ['message' => $e->getMessage()]);

            return ['status' => 'unavailable', 'message' => null];
        }

        if (!$response->successful()) {
            Log::warning('RunAgentAction: xAI HTTP error (stream)', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return ['status' => 'unavailable', 'message' => null];
        }

        $content = '';
        $toolCalls = [];
        $sawData = false;

        try {
            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $chunk = $body->read(2048);
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') {
                        break 2;
                    }

                    $json = json_decode($data, true);
                    if (!is_array($json)) {
                        continue;
                    }
                    $sawData = true;

                    $delta = data_get($json, 'choices.0.delta');
                    if (!is_array($delta)) {
                        continue;
                    }

                    if (is_string($delta['content'] ?? null) && $delta['content'] !== '') {
                        $content .= $delta['content'];
                        $channel->onDelta($delta['content']);
                    }

                    foreach (is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $fragment) {
                        if (!is_array($fragment)) {
                            continue;
                        }
                        $index = is_int($fragment['index'] ?? null) ? $fragment['index'] : count($toolCalls);
                        $toolCalls[$index] ??= [
                            'id' => null,
                            'type' => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                        if (is_string($fragment['id'] ?? null) && $fragment['id'] !== '') {
                            $toolCalls[$index]['id'] = $fragment['id'];
                        }
                        if (is_string(data_get($fragment, 'function.name'))) {
                            $toolCalls[$index]['function']['name'] .= data_get($fragment, 'function.name');
                        }
                        $args = data_get($fragment, 'function.arguments');
                        if (is_string($args)) {
                            $toolCalls[$index]['function']['arguments'] .= $args;
                        } elseif (is_array($args)) {
                            $toolCalls[$index]['function']['arguments'] = json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}';
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('RunAgentAction: stream read failed', ['message' => $e->getMessage()]);

            if ($content === '' && $toolCalls === []) {
                return ['status' => 'unavailable', 'message' => null];
            }
        }

        if (!$sawData) {
            return ['status' => 'ok', 'message' => null];
        }

        ksort($toolCalls);
        $toolCalls = array_values(array_map(static function (array $call): array {
            $call['id'] ??= 'call_' . uniqid();

            return $call;
        }, $toolCalls));

        $message = ['role' => 'assistant', 'content' => $content !== '' ? $content : null];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        return ['status' => 'ok', 'message' => $message];
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
