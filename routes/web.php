<?php

use App\Http\Controllers\Api\WellKnownController;
use App\Http\Controllers\Web\AiController as WebAiController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\LanguageController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\VerifierController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'home'])->name('home');

Route::get('/.well-known/triproof-key.json', WellKnownController::class)->name('well-known.key');

Route::post('/language', [LanguageController::class, 'update'])->name('language.update');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:triproof-login')
        ->name('login.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/orders/{po}/export', [OrderController::class, 'export'])->name('orders.export');
    Route::get('/orders/{po}/merkle', [OrderController::class, 'merkle'])->name('orders.merkle');
    Route::get('/orders/{po}/lookup', [OrderController::class, 'lookup'])->name('orders.lookup');

    Route::post('/recall', [OrderController::class, 'recall'])->name('recall.trigger');
    Route::post('/ai/analyze', [WebAiController::class, 'analyze'])->name('ai.analyze');

    Route::get('/verify', [VerifierController::class, 'index'])->name('verify.index');
    Route::post('/verify', [VerifierController::class, 'verify'])->name('verify.check');

    Route::middleware('role:global_buyer,admin')->group(function () {
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/{po}/generate', [OrderController::class, 'generate'])->name('orders.generate');
    });

    Route::middleware('role:factory_qa,admin')->group(function () {
        Route::post('/orders/{po}/pack', [OrderController::class, 'pack'])->name('orders.pack');
        Route::post('/rework', [OrderController::class, 'rework'])->name('rework.reconcile');
        Route::get('/rework/template', [OrderController::class, 'reworkTemplate'])->name('rework.template');
    });
});
