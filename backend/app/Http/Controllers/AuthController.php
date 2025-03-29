<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|string|max:255|email:dns,rfs',
            'password' => 'required|string|min:8',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !password_verify($data['password'], $user->password)) {
            abort(401, 'Invalid credentials');
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255|email:dns,rfs|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create($data);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }
}
