<?php

namespace App\Providers;

use App\Models\AiAnomalyReport;
use App\Models\DocSection;
use App\Models\DocTeamMember;
use App\Models\Factory;
use App\Models\PurchaseOrder;
use App\Models\RecallEvent;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\SigningKey;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->bustDocsTelemetryOnChanges();

        if (app()->isProduction()) {
            DB::prohibitDestructiveCommands();

            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
        }
    }

    private function bustDocsTelemetryOnChanges(): void
    {
        $models = [
            Factory::class,
            User::class,
            PurchaseOrder::class,
            SerialRegistry::class,
            ScanLog::class,
            RecallEvent::class,
            AiAnomalyReport::class,
            SigningKey::class,
            DocSection::class,
            DocTeamMember::class,
        ];

        foreach ($models as $model) {
            $model::saved(fn () => Cache::forget('docs_live_telemetry'));
            $model::deleted(fn () => Cache::forget('docs_live_telemetry'));
        }
    }

    private function configureRateLimiting(): void
    {
        $response = fn (Request $request, array $headers) => ApiResponse::error(
            'rate_limited',
            'Too many requests. Please slow down and retry later.',
            429
        )->withHeaders($headers);

        RateLimiter::for('triproof-login', fn (Request $request) => Limit::perMinute(
            (int) config('triproof.rate_limits.login', 10)
        )->by('login:'.$request->ip())->response($response));

        RateLimiter::for('triproof-api', fn (Request $request) => Limit::perMinute(
            (int) config('triproof.rate_limits.api', 120)
        )->by('api:'.($request->user()?->id ?? $request->ip()))->response($response));

        RateLimiter::for('triproof-verify', fn (Request $request) => Limit::perMinute(
            (int) config('triproof.rate_limits.verify', 600)
        )->by('verify:'.($request->user()?->id ?? $request->ip()))->response($response));

        RateLimiter::for('triproof-ai', fn (Request $request) => Limit::perMinute(
            (int) config('triproof.rate_limits.ai', 20)
        )->by('ai:'.($request->user()?->id ?? $request->ip()))->response($response));

        RateLimiter::for('triproof-export', fn (Request $request) => Limit::perMinute(
            (int) config('triproof.rate_limits.export', 30)
        )->by('export:'.($request->user()?->id ?? $request->ip()))->response($response));

        RateLimiter::for('triproof-mcp', fn (Request $request) => Limit::perMinute(
            (int) config('triproof.rate_limits.mcp', 60)
        )->by('mcp:'.($request->user()?->id ?? $request->ip()))->response($response));
    }
}
