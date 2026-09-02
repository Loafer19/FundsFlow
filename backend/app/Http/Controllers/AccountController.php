<?php

namespace App\Http\Controllers;

use App\Actions\Account\ExportAccountDataAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(
        private readonly ExportAccountDataAction $exportAccountData,
    ) {}

    public function updateCredentials(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'email' => [
                'required', 'string', 'max:255', 'email:dns,rfc',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => 'required|string|min:8',
        ]);

        $user->update($data);

        return response()->json([
            'user' => $user->load('identities'),
            'message' => 'Email and password saved successfully!',
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'moneyFormat' => 'required|string|max:32',
            'dateFormat' => 'required|string|max:64',
            'decimals' => 'required|boolean',
        ]);

        $user->preferences = array_merge($user->preferences ?? [], [
            'moneyFormat' => $data['moneyFormat'],
            'dateFormat' => $data['dateFormat'],
            'decimals' => $data['decimals'],
        ]);
        $user->save();

        return response()->json([
            'user' => $user->load('identities'),
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        // Compact JSON (no pretty-print) — faster and smaller with large transaction sets.
        return response()->json(
            $this->exportAccountData->execute($request->user()),
            200,
            [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function mcpTokenStatus(Request $request): JsonResponse
    {
        $active = $request->user()->tokens()->where('name', 'mcp')->exists();

        return response()->json([
            'active' => $active,
        ]);
    }

    public function createMcpToken(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()->where('name', 'mcp')->delete();

        $plainTextToken = $user->createToken('mcp')->plainTextToken;

        return response()->json([
            'token' => $plainTextToken,
            'message' => 'MCP token created. Copy it now — it will not be shown again.',
        ]);
    }

    public function revokeMcpToken(Request $request): JsonResponse
    {
        $request->user()->tokens()->where('name', 'mcp')->delete();

        return response()->json([
            'message' => 'MCP token revoked.',
            'active' => false,
        ]);
    }
}
