<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiClient;
use App\Services\Crypto\MasterKeyService;
use App\Services\Crypto\SerialCryptoService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(MasterKeyService $keys, AiClient $ai, SerialCryptoService $crypto): JsonResponse
    {
        $database = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = false;
        }

        $cryptoConfigured = $keys->isConfigured();
        $healthy = $database && $cryptoConfigured && function_exists('sodium_crypto_sign_verify_detached');

        return ApiResponse::success([
            'status' => $healthy ? 'ok' : 'degraded',
            'version' => '1.0.0',
            'php' => PHP_VERSION,
            'database' => $database ? 'up' : 'down',
            'sodium' => function_exists('sodium_crypto_sign_verify_detached') ? 'available' : 'missing',
            'signing_keys' => $cryptoConfigured ? 'configured' : 'missing',
            'ai' => $ai->isConfigured() ? $ai->provider() : 'fallback_only',
            'blacklist_gzip' => function_exists('gzencode') ? 'available' : 'missing',
            'time' => now()->toIso8601String(),
        ], $healthy ? 'All critical dependencies are available.' : 'One or more critical dependencies are unavailable.', $healthy ? 200 : 503);
    }
}
