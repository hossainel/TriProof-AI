<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PackingController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RecallController;
use App\Http\Controllers\Api\ReworkController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->middleware('throttle:triproof-api');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:triproof-login');

Route::middleware(['api.auth', 'throttle:triproof-api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/orders', [PurchaseOrderController::class, 'index']);
    Route::post('/orders', [PurchaseOrderController::class, 'store']);
    Route::post('/orders/generate-batch', [PurchaseOrderController::class, 'generateBatch']);
    Route::get('/orders/{po}/merkle', [PurchaseOrderController::class, 'merkle']);

    Route::post('/units/pack-batch', [PackingController::class, 'pack']);
    Route::post('/recall/trigger', [RecallController::class, 'trigger']);
    Route::post('/rework/reconcile', [ReworkController::class, 'reconcile']);

    Route::get('/notifications/{deviceToken}', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);
});

Route::middleware(['api.auth', 'throttle:triproof-verify'])->group(function () {
    Route::post('/verify/online-crosscheck', [VerificationController::class, 'onlineCrosscheck']);
});

Route::middleware(['api.auth', 'throttle:triproof-export'])->group(function () {
    Route::get('/orders/{po}/export', [PurchaseOrderController::class, 'export'])->name('api.orders.export');
});

Route::middleware(['api.auth', 'throttle:triproof-ai'])->group(function () {
    Route::post('/ai/analyze', [AiController::class, 'analyze']);
});
