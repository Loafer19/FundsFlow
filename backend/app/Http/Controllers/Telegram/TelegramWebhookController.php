<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $expected = (string) config('services.telegram.webhook_secret');
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if($expected === '', 500);

        if (!hash_equals($expected, $secret)) {
            abort(403);
        }

        $update = $request->all();
        $updateId = $update['update_id'] ?? null;

        // Telegram redelivers an update when it doesn't get a fast 2xx; process each update_id once.
        if (is_int($updateId) && !Cache::add("tg_update:{$updateId}", 1, now()->addDay())) {
            return response()->noContent();
        }

        // AI work (agent, vision, STT, album debounce) can take minutes, which would trip Telegram's
        // webhook timeout. There is no queue worker in the deployment, so run the job after the
        // response is flushed (FrankenPHP supports fastcgi_finish_request) instead of queueing it.
        ProcessTelegramUpdateJob::dispatchAfterResponse($update);

        return response()->noContent();
    }
}
