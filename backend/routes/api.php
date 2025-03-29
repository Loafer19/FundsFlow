<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('transactions', TransactionController::class);
});
