<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Strict only on credential entry — not on /me/logout (those fire on every refresh).
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/telegram-code', [AuthController::class, 'loginWithTelegramCode']);
    });

    // Register before /{provider} so "me" is not captured as a provider slug.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/{provider}', [AuthController::class, 'redirectToProvider']);
        Route::get('/{provider}/callback', [AuthController::class, 'handleProviderCallback']);
    });
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('throttle:60,1');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/bootstrap', BootstrapController::class);

    Route::apiResource('tags', TagController::class)->except(['show']);
    Route::apiResource('transactions', TransactionController::class)->except(['show']);
    Route::apiResource('budgets', BudgetController::class)->except(['show']);
    Route::post('/budgets/{budget}/pause', [BudgetController::class, 'pause']);
    Route::post('/budgets/{budget}/resume', [BudgetController::class, 'resume']);
    Route::apiResource('recurring-transactions', RecurringTransactionController::class)
        ->except(['show'])
        ->parameters(['recurring-transactions' => 'recurringTransaction']);
    Route::post('/identities/telegram/link-code', [IdentityController::class, 'telegramLinkCode']);
    Route::put('/account/credentials', [AccountController::class, 'updateCredentials']);
    Route::patch('/account/preferences', [AccountController::class, 'updatePreferences']);
    Route::get('/account/export', [AccountController::class, 'export'])
        ->middleware('throttle:10,1');
});
