<?php

use App\Http\Controllers\Api\WellKnownController;
use App\Http\Controllers\Web\AdminDirectoryController;
use App\Http\Controllers\Web\AdminDocsController;
use App\Http\Controllers\Web\AiController as WebAiController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DocsController;
use App\Http\Controllers\Web\LanguageController;
use App\Http\Controllers\Web\OrderController;
use App\Http\Controllers\Web\PublicPageController;
use App\Http\Controllers\Web\PublicPassportController;
use App\Http\Controllers\Web\VerifierController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'home'])->name('home');

Route::get('/.well-known/triproof-key.json', WellKnownController::class)->name('well-known.key');

Route::get('/about', [PublicPageController::class, 'about'])->name('about');
Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('privacy');
Route::get('/terms', [PublicPageController::class, 'terms'])->name('terms');

Route::post('/language', [LanguageController::class, 'update'])->name('language.update');
Route::get('/language/{locale}', [LanguageController::class, 'change'])->name('locale.switch');

Route::get('/v/{payload}', PublicPassportController::class)
    ->middleware('throttle:triproof-api')
    ->name('public.passport');

Route::prefix('docs')->middleware('docs.accessible')->group(function () {
    Route::get('/', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/export/pdf', [DocsController::class, 'exportPdf'])->name('docs.export.pdf');
    Route::get('/export/markdown', [DocsController::class, 'exportMarkdown'])->name('docs.export.markdown');
});

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
    Route::get('/orders/{po}/qr-stickers', [OrderController::class, 'qrStickers'])
        ->middleware('throttle:triproof-export')
        ->name('orders.qr-stickers');
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

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/manage', [AdminDirectoryController::class, 'index'])->name('manage');

        Route::post('/buyers', [AdminDirectoryController::class, 'storeBuyer'])->name('buyers.store');
        Route::put('/buyers/{user}', [AdminDirectoryController::class, 'updateBuyer'])->name('buyers.update');
        Route::delete('/buyers/{user}', [AdminDirectoryController::class, 'destroyBuyer'])->name('buyers.destroy');

        Route::post('/factories', [AdminDirectoryController::class, 'storeFactory'])->name('factories.store');
        Route::put('/factories/{factory}', [AdminDirectoryController::class, 'updateFactory'])->name('factories.update');
        Route::delete('/factories/{factory}', [AdminDirectoryController::class, 'destroyFactory'])->name('factories.destroy');

        Route::post('/operators', [AdminDirectoryController::class, 'storeOperator'])->name('operators.store');
        Route::put('/operators/{user}', [AdminDirectoryController::class, 'updateOperator'])->name('operators.update');
        Route::delete('/operators/{user}', [AdminDirectoryController::class, 'destroyOperator'])->name('operators.destroy');

        Route::put('/settings', [AdminDirectoryController::class, 'updateSettings'])->name('settings.update');

        Route::get('/docs', [AdminDocsController::class, 'index'])->name('docs.index');
        Route::put('/docs/settings', [AdminDocsController::class, 'updateSettings'])->name('docs.settings.update');
        Route::put('/docs/sections/{section}', [AdminDocsController::class, 'updateSection'])->name('docs.sections.update');
        Route::post('/docs/sections/{section}/publish', [AdminDocsController::class, 'publishSection'])->name('docs.sections.publish');
        Route::post('/docs/reorder', [AdminDocsController::class, 'reorderSections'])->name('docs.reorder');
        Route::post('/docs/revisions/{revision}/restore', [AdminDocsController::class, 'restoreRevision'])->name('docs.revisions.restore');
        Route::post('/docs/team', [AdminDocsController::class, 'storeTeamMember'])->name('docs.team.store');
        Route::put('/docs/team/{member}', [AdminDocsController::class, 'updateTeamMember'])->name('docs.team.update');
        Route::delete('/docs/team/{member}', [AdminDocsController::class, 'destroyTeamMember'])->name('docs.team.destroy');
    });
});
