<?php

namespace App\Http\Controllers;

use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
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

    public function register(Request $request): JsonResponse
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

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully!',
        ]);
    }

    public function redirectToProvider($provider): RedirectResponse
    {
        abort_unless(in_array($provider, User::AUTH_PROVIDERS), 404);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function handleProviderCallback($provider): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($provider, User::AUTH_PROVIDERS), 404);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();

            $provideField = $provider . '_id';

            $user = User::firstOrCreate(
                ['email' => $socialUser->getEmail()],
                [
                    'name' => $socialUser->getName(),
                    $provideField => $socialUser->getId(),
                    'password' => str()->random(16),
                ]
            );

            if ($user->{$provideField} !== $socialUser->getId()) {
                $user->update([
                    $provideField => $socialUser->getId(),
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            $url = url()->query(config('app.frontend_url'), ['token' => $token]);

            return redirect($url);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Authentication failed: ' . $e->getMessage(),
            ], 401);
        }
    }
}
