<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function updateCredentials(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'email' => [
                'required', 'string', 'max:255', 'email:dns,rfs',
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
}
