<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\User;
use App\Support\TelegramWebAppInitData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Requires PHPUnit + Laravel testing bootstrap (not currently in composer.json).
 *
 * To enable:
 *   cd backend
 *   composer require --dev phpunit/phpunit laravel/pint
 *   # add Tests\ namespace to composer autoload-dev, phpunit.xml, tests/TestCase.php
 *   php artisan test --filter=TelegramWebAppAuthTest
 *
 * Or unit-test the HMAC helper alone:
 *   php -r 'require "vendor/autoload.php"; ...'
 */
class TelegramWebAppAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $botToken = '123456:ABC-DEF_test_token';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.telegram.bot_token', $this->botToken);
    }

    public function test_rejects_invalid_hash(): void
    {
        $response = $this->postJson('/api/auth/telegram-webapp', [
            'initData' => 'user=' . urlencode('{"id":1}') . '&auth_date=' . time() . '&hash=deadbeef',
        ]);

        $response->assertStatus(422);
    }

    public function test_returns_needs_auth_when_unlinked(): void
    {
        $initData = $this->buildInitData([
            'id' => 991001,
            'username' => 'mini_user',
            'first_name' => 'Mini',
        ]);

        $response = $this->postJson('/api/auth/telegram-webapp', [
            'initData' => $initData,
        ]);

        $response->assertOk()
            ->assertJson([
                'needs_auth' => true,
                'telegram' => [
                    'id' => 991001,
                    'username' => 'mini_user',
                    'first_name' => 'Mini',
                ],
            ])
            ->assertJsonMissing(['token']);
    }

    public function test_logs_in_when_telegram_identity_linked(): void
    {
        $user = User::create([
            'email' => 'linked@example.com',
            'password' => 'password123',
        ]);

        $user->identities()->create([
            'provider' => 'telegram',
            'external_id' => '991002',
            'meta' => ['username' => 'old'],
        ]);

        $initData = $this->buildInitData([
            'id' => 991002,
            'username' => 'linked_user',
            'first_name' => 'Linked',
        ]);

        $response = $this->postJson('/api/auth/telegram-webapp', [
            'initData' => $initData,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'token']);
    }

    public function test_link_success_for_authenticated_user(): void
    {
        $user = User::create([
            'email' => 'linkme@example.com',
            'password' => 'password123',
        ]);

        Sanctum::actingAs($user);

        $initData = $this->buildInitData([
            'id' => 991003,
            'username' => 'to_link',
            'first_name' => 'ToLink',
        ]);

        $response = $this->postJson('/api/auth/telegram-webapp/link', [
            'initData' => $initData,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseHas('identities', [
            'user_id' => $user->id,
            'provider' => 'telegram',
            'external_id' => '991003',
        ]);
    }

    public function test_link_conflict_when_identity_belongs_to_another_user(): void
    {
        $owner = User::create(['email' => 'owner@example.com', 'password' => 'password123']);
        $other = User::create(['email' => 'other@example.com', 'password' => 'password123']);

        $owner->identities()->create([
            'provider' => 'telegram',
            'external_id' => '991004',
            'meta' => [],
        ]);

        Sanctum::actingAs($other);

        $initData = $this->buildInitData([
            'id' => 991004,
            'username' => 'taken',
            'first_name' => 'Taken',
        ]);

        $response = $this->postJson('/api/auth/telegram-webapp/link', [
            'initData' => $initData,
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Identity::where('external_id', '991004')->count());
    }

    public function test_validator_rejects_stale_auth_date(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $initData = $this->buildInitData(
            ['id' => 1, 'first_name' => 'Old'],
            authDate: time() - 90000,
        );

        (new TelegramWebAppInitData)->parse($initData);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function buildInitData(array $user, ?int $authDate = null): string
    {
        $authDate ??= time();
        $params = [
            'auth_date' => (string) $authDate,
            'user' => json_encode($user, JSON_UNESCAPED_UNICODE),
        ];

        ksort($params);
        $dataCheckString = implode("\n", array_map(
            static fn (string $key, string $value) => "{$key}={$value}",
            array_keys($params),
            array_values($params),
        ));

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($params);
    }
}
