<?php

namespace App\Http\Controllers;

use App\Actions\Identities\GenerateTelegramLinkCodeAction;
use App\Support\TelegramCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function telegramLinkCode(Request $request, GenerateTelegramLinkCodeAction $action): JsonResponse
    {
        $code = $action->execute($request->user());

        return response()->json([
            // Raw code goes into the t.me deep link; display is the grouped form.
            'code' => $code,
            'display' => TelegramCode::format($code),
        ]);
    }
}
