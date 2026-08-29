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
        $expected = (string) config('services.telegram.webhook_secret');
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if($expected === '', 500);

        if (!hash_equals($expected, $secret)) {
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
