<?php

namespace App\Http\Controllers;

use App\Actions\Identities\LinkTelegramIdentityAction;
use App\Actions\Identities\ListIdentitiesAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class IdentityController extends Controller
{
    public function index(ListIdentitiesAction $action): JsonResponse
    {
        $identities = $action->execute(auth()->user());

        return response()->json($identities->map(fn ($identity) => [
            'provider' => $identity->provider,
            'meta' => $identity->meta,
            'created_at' => $identity->created_at,
        ]));
    }

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
            'message' => 'Telegram account linked successfully!',
        ]);
    }
}
