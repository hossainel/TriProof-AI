<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OfflineBlacklistService;
use Illuminate\Http\JsonResponse;

class BlacklistDeltaController extends Controller
{
    public function __invoke(OfflineBlacklistService $service): JsonResponse
    {
        $built = $service->build();
        $compressed = base64_decode($built['payload'], true);
        $internal = $compressed === false ? [] : (json_decode(gzdecode($compressed) ?: '[]', true) ?: []);

        $entries = [];
        foreach ($internal['recalled_batches'] ?? [] as $batch) {
            $key = is_array($batch) ? ($batch['batch'] ?? null) : $batch;
            if (is_string($key) && $key !== '') {
                $entries[] = ['serial_or_batch' => $key, 'reason' => 'Recalled batch', 'updated_at' => $internal['generated_at'] ?? now()->toIso8601String()];
            }
        }
        foreach ($internal['cloned_serials'] ?? [] as $clone) {
            $key = is_array($clone) ? ($clone['serial'] ?? null) : $clone;
            if (is_string($key) && $key !== '') {
                $entries[] = ['serial_or_batch' => $key, 'reason' => 'Cloned serial', 'updated_at' => $internal['generated_at'] ?? now()->toIso8601String()];
            }
        }

        $bloom = $internal['bloom'] ?? [];
        $bloomFilter = [
            'bits_b64' => $bloom['bloom_bits_b64'] ?? '',
            'm_bits' => (int) ($bloom['m_bits'] ?? 0),
            'k_hashes' => (int) ($bloom['k_hashes'] ?? 0),
            'seed' => (int) ($bloom['seed'] ?? 0),
            'entries' => (int) ($bloom['entries'] ?? 0),
            'estimated_fp_rate' => $bloom['estimated_fp_rate'] ?? null,
        ];

        $max = (int) config('triproof.blacklist.max_bytes', 50000);
        $serverTime = now()->toIso8601String();

        do {
            $payload = [
                'success' => true,
                'server_time' => $serverTime,
                'entries' => $entries,
                'bloom_filter' => $bloomFilter,
                'meta' => [
                    'compressed_bytes' => $built['compressed_bytes'],
                    'limit_bytes' => $max,
                    'truncated' => ! empty($internal['truncated']) || count($entries) < (int) ($built['cloned_serials_total'] ?? 0),
                ],
            ];
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($encoded) <= $max || $entries === []) {
                break;
            }
            $entries = array_slice($entries, 0, max(0, intdiv(count($entries), 2)));
        } while (true);

        return response()->json(
            json_decode($encoded ?: '{}', true),
            200,
            ['Cache-Control' => 'no-store, no-cache, must-revalidate']
        );
    }
}
