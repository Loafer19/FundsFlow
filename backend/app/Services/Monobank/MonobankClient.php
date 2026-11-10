<?php

namespace App\Services\Monobank;

use App\Exceptions\MonobankException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MonobankClient
{
    /**
     * Sleep until this connection may call the personal API again (queued jobs only).
     */
    public function throttle(int $connectionId): void
    {
        $wait = $this->secondsUntilReady($connectionId);

        if ($wait > 0) {
            sleep(min($wait, 75));
        }
    }

    public function secondsUntilReady(int $connectionId): int
    {
        $availableAt = (int) Cache::get($this->cacheKey($connectionId), 0);

        return max(0, $availableAt - time());
    }

    public function markThrottled(int $connectionId): void
    {
        $seconds = max(1, (int) config('monobank.request_interval', 60));
        Cache::put($this->cacheKey($connectionId), time() + $seconds, $seconds + 10);
    }

    /**
     * @return array<string, mixed>
     */
    public function clientInfo(string $token, ?int $connectionId = null): array
    {
        $response = $this->request($token, 'get', '/personal/client-info');
        $this->touch($connectionId);

        return $this->jsonObject($response);
    }

    public function setWebhook(string $token, string $url, ?int $connectionId = null): void
    {
        $response = $this->request($token, 'post', '/personal/webhook', ['webHookUrl' => $url]);
        $this->touch($connectionId);

        if ($response->failed()) {
            throw $this->toException($response);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statement(string $token, string $accountId, int $from, int $to, ?int $connectionId = null): array
    {
        $path = '/personal/statement/' . rawurlencode($accountId) . '/' . $from . '/' . $to;
        $response = $this->request($token, 'get', $path);
        $this->touch($connectionId);

        $json = $response->json();

        if (!is_array($json) || (array_is_list($json) === false && isset($json['errorDescription']))) {
            throw $this->toException($response);
        }

        /** @var list<array<string, mixed>> $json */
        return array_values(array_filter($json, 'is_array'));
    }

    private function touch(?int $connectionId): void
    {
        if ($connectionId !== null) {
            $this->markThrottled($connectionId);
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function request(string $token, string $method, string $path, ?array $payload = null): Response
    {
        $pending = Http::baseUrl((string) config('monobank.base_url', 'https://api.monobank.ua'))
            ->withHeaders(['X-Token' => $token])
            ->acceptJson()
            ->timeout(25)
            ->connectTimeout(10);

        try {
            $response = $method === 'post'
                ? $pending->post($path, $payload ?? [])
                : $pending->get($path);
        } catch (\Throwable) {
            throw new MonobankException("Couldn't reach Monobank. Try again in a moment.", 502);
        }

        if ($response->failed()) {
            throw $this->toException($response);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(Response $response): array
    {
        $json = $response->json();

        if (!is_array($json)) {
            throw new MonobankException("Couldn't read the response from Monobank.", 502);
        }

        return $json;
    }

    private function toException(Response $response): MonobankException
    {
        $status = $response->status();
        $description = (string) ($response->json('errorDescription') ?? '');

        if ($status === 429) {
            return new MonobankException('Monobank allows one request per minute. Wait a moment and try again.', 429);
        }

        if (in_array($status, [401, 403], true) || str_contains(mb_strtolower($description), 'token')) {
            return new MonobankException(
                "That token didn't work. Open api.monobank.ua, copy a fresh personal token and try again.",
                422,
            );
        }

        return new MonobankException("Couldn't talk to Monobank right now. Try again in a moment.", 502);
    }

    private function cacheKey(int $connectionId): string
    {
        return "monobank:ratelimit:{$connectionId}";
    }
}
