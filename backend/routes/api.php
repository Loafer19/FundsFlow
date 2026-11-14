<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MonobankController;
use App\Http\Controllers\MonobankWebhookController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Controllers\TransactionAttachmentController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Strict only on credential entry — not on /me/logout (those fire on every refresh).
    Route::middleware('throttle:20,1,auth-login:')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/telegram-code', [AuthController::class, 'loginWithTelegramCode']);
        Route::post('/telegram-webapp', [AuthController::class, 'loginWithTelegramWebApp']);
    });

    // Register before /{provider} so "me" is not captured as a provider slug.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/telegram-webapp/link', [AuthController::class, 'linkTelegramWebApp'])
            ->middleware('throttle:20,1,tg-webapp-link:');
    });

    Route::middleware('throttle:30,1,auth-oauth:')->group(function () {
        Route::get('/{provider}', [AuthController::class, 'redirectToProvider']);
        Route::get('/{provider}/callback', [AuthController::class, 'handleProviderCallback']);
    });
});

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->middleware('throttle:60,1,tg-webhook:');

Route::get('/webhooks/monobank/{uuid}', [MonobankWebhookController::class, 'challenge'])
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->middleware('throttle:60,1,mono-webhook:');
Route::post('/webhooks/monobank/{uuid}', [MonobankWebhookController::class, 'handle'])
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->middleware('throttle:60,1,mono-webhook:');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/bootstrap', BootstrapController::class);

    Route::apiResource('tags', TagController::class)->except(['show']);
    Route::get('/transaction-types', [TransactionTypeController::class, 'index']);
    Route::post('/transaction-types', [TransactionTypeController::class, 'store'])->middleware('throttle:60,1,tx-types:');
    Route::match(['put', 'patch'], '/transaction-types/{type}', [TransactionTypeController::class, 'update'])->whereNumber('type')
        ->middleware('throttle:60,1,tx-types:');
    Route::delete('/transaction-types/{type}', [TransactionTypeController::class, 'destroy'])->whereNumber('type');
    Route::post('/transaction-types/{type}/restore', [TransactionTypeController::class, 'restore'])->whereNumber('type');
    Route::post('/transactions/bulk', [TransactionController::class, 'storeBulk'])
        ->middleware('throttle:30,1,tx-bulk:');
    Route::put('/transactions/bulk', [TransactionController::class, 'updateBulk'])
        ->middleware('throttle:30,1,tx-bulk:');
    Route::patch('/transactions/bulk', [TransactionController::class, 'updateBulk'])
        ->middleware('throttle:30,1,tx-bulk:');
    Route::delete('/transactions/bulk', [TransactionController::class, 'destroyBulk'])
        ->middleware('throttle:30,1,tx-bulk:');
    Route::apiResource('transactions', TransactionController::class)->except(['show']);
    Route::post('/transactions/{transaction}/attachments', [TransactionAttachmentController::class, 'store'])
        ->middleware('throttle:60,1,attach-upload:');

    Route::post('/transactions/{transaction}/attachments/base64', [TransactionAttachmentController::class, 'storeBase64'])
        ->middleware('throttle:60,1,attach-upload:');
    Route::get('/transactions/{transaction}/attachments/{attachment}', [TransactionAttachmentController::class, 'show']);
    Route::delete('/transactions/{transaction}/attachments/{attachment}', [TransactionAttachmentController::class, 'destroy']);
    Route::post('/transactions/{transaction}/attachments/{attachment}/telegram', [TransactionAttachmentController::class, 'sendToTelegram'])
        ->middleware('throttle:30,1,attach-tg:');
    // Report PDF (one renderer for Settings and the assistant card; see RenderReportPdfAction).
    // Every throttle has its own limiter key (3rd arg): unprefixed throttle:N,M counters are shared per user across routes,
    // and every render also calls print-data, so the report must not eat the app-wide budget.
    Route::post('/report', [ReportController::class, 'store'])
        ->middleware('throttle:10,1,report:');
    // The only route a one-time `report:print` token is accepted on (App\Support\ReportPrintToken).
    Route::get('/report/print-data', [ReportController::class, 'printData'])
        ->name('report.print-data')
        ->middleware('throttle:30,1,report-print:');
    Route::apiResource('budgets', BudgetController::class)->except(['show']);

    Route::post('/budgets/{budget}/pause', [BudgetController::class, 'pause']);
    Route::post('/budgets/{budget}/resume', [BudgetController::class, 'resume']);
    Route::apiResource('recurring-transactions', RecurringTransactionController::class)
        ->except(['show'])
        ->parameters(['recurring-transactions' => 'recurringTransaction']);
    Route::post('/identities/telegram/link-code', [IdentityController::class, 'telegramLinkCode'])
        ->middleware('throttle:10,1,tg-link-code:');
    Route::put('/account/credentials', [AccountController::class, 'updateCredentials']);
    Route::patch('/account/preferences', [AccountController::class, 'updatePreferences']);
    Route::patch('/account/onboarding', [AccountController::class, 'updateOnboarding']);
    Route::get('/account/ai-quota', [AccountController::class, 'aiQuota']);
    Route::get('/account/mcp-token', [AccountController::class, 'mcpTokenStatus']);
    Route::post('/account/mcp-token', [AccountController::class, 'createMcpToken'])
        ->middleware('throttle:10,1,mcp-token:');
    Route::delete('/account/mcp-token', [AccountController::class, 'revokeMcpToken']);
    Route::get('/account/export', [AccountController::class, 'export'])
        ->middleware('throttle:10,1,export:');

    Route::get('/monobank', [MonobankController::class, 'show']);
    Route::post('/monobank/connect', [MonobankController::class, 'connect'])
        ->middleware('throttle:10,1,mono-connect:');
    Route::patch('/monobank', [MonobankController::class, 'update']);
    Route::post('/monobank/sync', [MonobankController::class, 'sync'])
        ->middleware('throttle:6,1,mono-sync:');
    Route::delete('/monobank', [MonobankController::class, 'destroy']);

    Route::get('/imports', [ImportController::class, 'index']);
    Route::get('/imports/{import}', [ImportController::class, 'show'])
        ->where('import', '[0-9a-fA-F-]{36}');
    Route::post('/imports', [ImportController::class, 'store'])
        ->middleware('throttle:10,1,import-create:');
    Route::patch('/imports/{import}', [ImportController::class, 'update'])
        ->where('import', '[0-9a-fA-F-]{36}');
    Route::patch('/imports/{import}/rows/{row}', [ImportController::class, 'updateRow'])
        ->where('import', '[0-9a-fA-F-]{36}')
        ->whereNumber('row');
    Route::post('/imports/{import}/commit', [ImportController::class, 'commit'])
        ->where('import', '[0-9a-fA-F-]{36}')
        ->middleware('throttle:6,1,import-commit:');
    Route::post('/imports/{import}/undo', [ImportController::class, 'undo'])
        ->where('import', '[0-9a-fA-F-]{36}')
        ->middleware('throttle:6,1,import-undo:');

    // Support requests. New ones are also capped per hour in CreateSupportTicketAction (bot + assistant too).
    Route::get('/support/tickets', [SupportTicketController::class, 'index']);
    Route::post('/support/tickets', [SupportTicketController::class, 'store'])
        ->middleware('throttle:5,10,support-create:');
    Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show'])->whereNumber('ticket');
    Route::post('/support/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])
        ->whereNumber('ticket')
        ->middleware('throttle:20,10,support-reply:');

    Route::post('/agent/chat', [AgentController::class, 'chat'])
        ->middleware('throttle:20,1,agent-chat:');
    Route::post('/agent/confirm', [AgentController::class, 'confirm'])
        ->middleware('throttle:30,1,agent-confirm:');
    Route::post('/agent/report', [ReportController::class, 'store'])
        ->middleware('throttle:10,1,report:');
    Route::get('/agent/history', [AgentController::class, 'history'])
        ->middleware('throttle:60,1,agent-history:');
    Route::delete('/agent/conversation', [AgentController::class, 'reset'])
        ->middleware('throttle:30,1,agent-reset:');
});
