<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/telegram-code', [AuthController::class, 'loginWithTelegramCode']);
    Route::get('/me', [AuthController::class, 'me'])
        ->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::get('/{provider}', [AuthController::class, 'redirectToProvider']);
    Route::get('/{provider}/callback', [AuthController::class, 'handleProviderCallback']);
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('tags', TagController::class);
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('budgets', BudgetController::class)->except(['show']);
    Route::post('/budgets/{budget}/pause', [BudgetController::class, 'pause']);
    Route::post('/budgets/{budget}/resume', [BudgetController::class, 'resume']);
    Route::post('/identities/telegram/link-code', [IdentityController::class, 'telegramLinkCode']);
    Route::put('/account/credentials', [AccountController::class, 'updateCredentials']);
});
