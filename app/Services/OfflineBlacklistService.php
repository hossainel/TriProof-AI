<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Models\RecallEvent;
use App\Models\SerialRegistry;
use App\Support\BloomFilter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OfflineBlacklistService
{
    /**
     * Build a gzip+Base64 offline package guaranteed to stay below the configured
     * byte cap (50 KB by contract, 48 KB target).
     */
    public function build(): array
    {
        if (! function_exists('gzencode')) {
            throw new RuntimeException('The zlib extension is required to build the offline blacklist.');
        }

        $maxBytes = max(4096, (int) config('triproof.blacklist.max_bytes', 50000));
        $maxCloned = max(0, (int) config('triproof.blacklist.max_cloned', 5000));

        $recalled = collect();

        SerialRegistry::query()
            ->where('status', SerialStatus::Recalled->value)
            ->select('factory_id', 'machine_id', 'batch_id', DB::raw('MAX(updated_at) as last_seen'))
            ->groupBy('factory_id', 'machine_id', 'batch_id')
            ->orderByDesc('last_seen')
            ->limit(2000)
            ->get()
            ->each(function ($row) use ($recalled) {
                $key = "{$row->factory_id}-{$row->machine_id}-{$row->batch_id}";
                $recalled[$key] = [
                    'batch' => $key,
                    'factory_id' => $row->factory_id,
                    'machine_id' => $row->machine_id,
                    'batch_id' => $row->batch_id,
                    'last_seen' => (string) $row->last_seen,
                ];
            });

        RecallEvent::query()
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->each(function (RecallEvent $row) use ($recalled) {
                $key = "{$row->factory_id}-{$row->machine_id}-{$row->batch_id}";
                if (! isset($recalled[$key])) {
                    $recalled[$key] = [
                        'batch' => $key,
                        'factory_id' => $row->factory_id,
                        'machine_id' => $row->machine_id,
                        'batch_id' => $row->batch_id,
                        'last_seen' => $row->created_at?->toDateTimeString(),
                    ];
                }
            });

        $recalled = $recalled->sortByDesc('last_seen')->values();

        $clonedTotal = SerialRegistry::query()
            ->where('status', SerialStatus::ClonedFlagged->value)
            ->count();

        $cloned = SerialRegistry::query()
            ->where('status', SerialStatus::ClonedFlagged->value)
            ->orderByDesc('updated_at')
            ->limit($maxCloned)
            ->get(['outer_serial', 'factory_id', 'machine_id', 'batch_id', 'updated_at'])
            ->map(fn (SerialRegistry $serial) => [
                'serial' => $serial->outer_serial,
                'batch' => "{$serial->factory_id}-{$serial->machine_id}-{$serial->batch_id}",
                'flagged_at' => $serial->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $truncated = $clonedTotal > count($cloned);
        $clonedTotal = max($clonedTotal, count($cloned));

        // The bloom filter carries every flagged unit hash; explicit lists remain for actionable
        // batch recalls. The package format stays v1 so older Android builds keep working.
        $flaggedHashes = SerialRegistry::query()
            ->whereIn('status', [SerialStatus::ClonedFlagged->value, SerialStatus::Recalled->value])
            ->pluck('unit_hash')
            ->all();
        $bloom = BloomFilter::build(
            $flaggedHashes,
            max((int) config('triproof.blacklist.bloom_target_entries', 30000), count($flaggedHashes)),
            0.001,
            (int) config('triproof.blacklist.bloom_max_bits', 288000),
        );

        do {
            $payload = [
                'format' => 'triproof-offline-blacklist/v1',
                'generated_at' => now()->toIso8601String(),
                'recalled_batches' => $recalled->values()->all(),
                'cloned_serials' => $cloned,
                'counts' => [
                    'recalled_batches' => $recalled->count(),
                    'cloned_serials' => count($cloned),
                    'cloned_serials_total' => $clonedTotal,
                ],
                'truncated' => $truncated,
                'bloom' => $bloom,
            ];

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Unable to encode the offline blacklist.');
            }

            $compressed = gzencode($json, 9);
            if ($compressed === false) {
                throw new RuntimeException('Unable to compress the offline blacklist.');
            }

            $encoded = base64_encode($compressed);

            if (strlen($encoded) <= $maxBytes) {
                break;
            }

            if ($cloned !== []) {
                $cloned = array_slice($cloned, 0, max(0, intdiv(count($cloned), 2)));
                $truncated = true;

                continue;
            }

            if ($recalled->isNotEmpty()) {
                $recalled = $recalled->slice(0, max(0, intdiv($recalled->count(), 2)))->values();
                $truncated = true;

                continue;
            }

            throw new RuntimeException('Offline blacklist metadata exceeds the configured byte cap.');
        } while (true);

        return [
            'encoding' => 'gzip+base64',
            'compression' => 'gzip',
            'payload' => $encoded,
            'compressed_bytes' => strlen($encoded),
            'uncompressed_bytes' => strlen($json),
            'limit_bytes' => $maxBytes,
            'sha256' => hash('sha256', $compressed),
            'generated_at' => now()->toIso8601String(),
            'recalled_batches' => $payload['counts']['recalled_batches'],
            'cloned_serials' => $payload['counts']['cloned_serials'],
            'cloned_serials_total' => $payload['counts']['cloned_serials_total'],
            'truncated' => $payload['truncated'],
            'bloom' => [
                'bloom_bits_b64' => $bloom['bloom_bits_b64'],
                'm_bits' => $bloom['m_bits'],
                'k_hashes' => $bloom['k_hashes'],
                'seed' => $bloom['seed'],
                'entries' => $bloom['entries'],
                'target_entries' => $bloom['target_entries'],
                'estimated_fp_rate' => $bloom['estimated_fp_rate'],
            ],
        ];
    }
}
