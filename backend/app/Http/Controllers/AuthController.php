<?php

namespace App\Http\Controllers;

use App\Actions\Auth\AuthenticateTelegramWebAppAction;
use App\Actions\Auth\LinkTelegramWebAppAction;
use App\Models\Identity;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\TelegramCode;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    private const MAX_AUTH_TOKENS = 5;

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

    public function register(Request $request, AppSettings $settings): JsonResponse
    {
        // Admin → Settings → "New registrations": existing accounts can still sign in.
        abort_unless($settings->registrationEnabled(), 403, 'New sign-ups are currently closed.');

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
            // Users may paste the grouped form (K7QM-2XPA) or type it in lowercase.
            'code' => 'required|string|max:32',
        ]);

        $userId = TelegramCode::pull('telegram_login', $data['code']);

        if (!$userId) {
            abort(422, 'This code is invalid or has expired.');
        }

        $user = User::findOrFail($userId);

        return response()->json([
            'user' => $user->load('identities'),
            'token' => $this->issueAuthToken($user),
        ]);
    }

    public function loginWithTelegramWebApp(Request $request, AuthenticateTelegramWebAppAction $action): JsonResponse
    {
        $data = $request->validate([
            'initData' => 'required|string',
        ]);

        $result = $action->execute($data['initData']);

        if (isset($result['needs_auth'])) {
            return response()->json([
                'needs_auth' => true,
                'telegram' => $result['telegram'],
            ]);
        }

        /** @var User $user */
        $user = $result['user'];

        return response()->json([
            'user' => $user->load('identities'),
            'token' => $this->issueAuthToken($user),
        ]);
    }

    public function linkTelegramWebApp(Request $request, LinkTelegramWebAppAction $action): JsonResponse
    {
        $data = $request->validate([
            'initData' => 'required|string',
        ]);

        $user = $action->execute($request->user(), $data['initData']);

        return response()->json([
            'user' => $user,
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
                    if (!app(AppSettings::class)->registrationEnabled()) {
                        return redirect($frontend . '#auth_error=' . urlencode('New sign-ups are currently closed'));
                    }

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

            if ($user->isBlocked()) {
                return redirect($frontend . '#auth_error=' . urlencode('Account blocked'));
            }

            return redirect($frontend . '#token=' . urlencode($this->issueAuthToken($user)));
        } catch (Exception $e) {
            return redirect($frontend . '#auth_error=' . urlencode('Authentication failed'));
        }
    }

    private function issueAuthToken(User $user): string
    {
        abort_if($user->isBlocked(), 403, 'Account blocked');

        $existing = $user->tokens()
            ->where('name', 'auth_token')
            ->orderBy('created_at')
            ->get();

        $overflow = $existing->count() - (self::MAX_AUTH_TOKENS - 1);

        if ($overflow > 0) {
            $existing->take($overflow)->each->delete();
        }

        return $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;
    }
}
