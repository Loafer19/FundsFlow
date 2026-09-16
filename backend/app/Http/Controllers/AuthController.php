<?php

namespace App\Http\Controllers;

use App\Models\Identity;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|max:255|email:dns,rfc',
            'password' => 'required|string|min:8',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            abort(401, 'Invalid credentials');
        }

        return response()->json([
            'user' => $user->load('identities'),
            'token' => $this->issueAuthToken($user),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|max:255|email:dns,rfc|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create($data);

        return response()->json([
            'user' => $user->load('identities'),
            'token' => $this->issueAuthToken($user),
        ], 201);
    }

    public function loginWithTelegramCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|size:8|alpha_num',
        ]);

        $code = strtoupper($data['code']);
        $userId = Cache::pull("telegram_login:{$code}");

        if (!$userId) {
            abort(422, 'This code is invalid or has expired.');
        }

        $user = User::findOrFail($userId);

        return response()->json([
            'user' => $user->load('identities'),
            'token' => $this->issueAuthToken($user),
        ]);
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

            return redirect($frontend . '#token=' . urlencode($this->issueAuthToken($user)));
        } catch (Exception $e) {
            return redirect($frontend . '#auth_error=' . urlencode('Authentication failed'));
        }
    }

    private function issueAuthToken(User $user): string
    {
        $user->tokens()->where('name', 'auth_token')->delete();

        return $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
    }
}

