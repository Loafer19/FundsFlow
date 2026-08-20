<?php

namespace App\Http\Controllers;

use App\Actions\Identities\LinkTelegramIdentityAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class IdentityController extends Controller
{
    public function linkTelegram(Request $request, LinkTelegramIdentityAction $action): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string',
        ]);

        try {
            $action->execute($request->user(), $data['code']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Telegram привʼязано успішно!",
        ]);
    }
}
