<?php

namespace App\Http\Controllers;

use App\Actions\Identities\GenerateTelegramLinkCodeAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function telegramLinkCode(Request $request, GenerateTelegramLinkCodeAction $action): JsonResponse
    {
        $code = $action->execute($request->user());

        return response()->json([
            'code' => $code,
        ]);
    }
}
