<?php

namespace App\Http\Controllers\Telegram;

use App\Channels\Telegram\TelegramBot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, TelegramBot $bot): Response
    {
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (!hash_equals((string) config('services.telegram.webhook_secret'), $secret)) {
            abort(403);
        }

        try {
            $bot->handle($request->all());
        } catch (Throwable $e) {
            report($e);
        }

        return response()->noContent();
    }
}
