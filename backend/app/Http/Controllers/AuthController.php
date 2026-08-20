<?php

namespace App\Http\Controllers;

use App\Models\Identity;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        if (!$user || !Hash::check($data['password'], $user->password)) {
            abort(401, 'Invalid credentials');
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user->load('identities'),
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
            'user' => $user->load('identities'),
            'token' => $token,
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load('identities'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully!',
        ]);
    }

    public function redirectToProvider(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, User::AUTH_PROVIDERS, true), 404);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function handleProviderCallback(string $provider): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($provider, User::AUTH_PROVIDERS, true), 404);

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
            $providerId = (string) $socialUser->getId();
            $email = $socialUser->getEmail();

            if (!$email) {
                return redirect($frontend . '#auth_error=' . urlencode('Email is required from provider'));
            }

            $meta = [
                'name' => $socialUser->getName(),
                'nickname' => $socialUser->getNickname(),
                'avatar' => $socialUser->getAvatar(),
            ];

            $identity = Identity::where('provider', $provider)->where('external_id', $providerId)->first();

            if ($identity) {
                $identity->update(['meta' => $meta]);
                $user = $identity->user;
            } else {
                // The provider vouches for control of this email, so an
                // existing account with the same email is linked rather
                // than blocked.
                $user = User::where('email', $email)->first();

                if (!$user) {
                    $user = User::create([
                        'name' => $socialUser->getName() ?: strstr($email, '@', true),
                        'email' => $email,
                        'password' => str()->random(32),
                    ]);
                }

                $user->identities()->create([
                    'provider' => $provider,
                    'external_id' => $providerId,
                    'meta' => $meta,
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return redirect($frontend . '#token=' . urlencode($token));
        } catch (Exception $e) {
            return redirect($frontend . '#auth_error=' . urlencode('Authentication failed'));
        }
    }
}
