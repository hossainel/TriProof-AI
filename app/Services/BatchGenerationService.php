<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Crypto\MasterKeyService;
use App\Services\Crypto\MerkleService;
use App\Services\Crypto\SerialCryptoService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class BatchGenerationService
{
    public function __construct(
        private readonly MasterKeyService $keys,
        private readonly SerialCryptoService $crypto,
        private readonly MerkleService $merkle,
    ) {}

    /**
     * Sign exactly N serial pairs inside a quota-locked transaction.
     */
    public function generate(PurchaseOrder $order, User $actor, array $input): array
    {
        if ($actor->isFactory()) {
            throw new BusinessRuleException(
                'factory_cannot_sign',
                'Factories cannot generate signatures. Serial batches are issued only by the buyer or an administrator.',
                403
            );
        }

        if (! $actor->isAdmin() && $order->buyer_id !== $actor->id) {
            throw new BusinessRuleException('forbidden', 'You do not own this purchase order.', 403);
        }

        if (! $this->keys->isConfigured()) {
            throw new BusinessRuleException(
                'crypto_unconfigured',
                'The HQ Ed25519 signing key is not configured. Run: php artisan triproof:keys --write',
                503
            );
        }

        $count = (int) ($input['count'] ?? 0);
        $max = (int) config('triproof.exports.max_generate', 50000);

        if ($count < 1) {
            throw new BusinessRuleException('invalid_count', 'Count must be at least 1.');
        }

        if ($count > $max) {
            throw new BusinessRuleException('count_too_large', "A single generation request may contain at most {$max} units.", 422, ['max' => $max]);
        }

        $machineId = $this->crypto->normalizeCode((string) ($input['machine_id'] ?? ''));
        $batchId = $this->crypto->normalizeCode((string) ($input['batch_id'] ?? ''));
        $factoryId = $order->factory_id;
        $prodDate = isset($input['prod_date'])
            ? CarbonImmutable::parse($input['prod_date'])->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $expDate = $prodDate->addDays($order->retail_expiry_days);
        $keyVersion = $this->keys->activeVersion($order->buyer_id);
        $dppDigest = $order->dpp_digest;

        try {
            $this->crypto->formatOuterSerial($factoryId, $machineId, $batchId, 1, $prodDate, $expDate, $dppDigest ? $keyVersion : null, $dppDigest);
        } catch (Throwable $e) {
            throw new BusinessRuleException('invalid_batch_identifiers', $e->getMessage());
        }

        return DB::transaction(function () use ($order, $actor, $input, $count, $factoryId, $machineId, $batchId, $prodDate, $expDate, $keyVersion, $dppDigest) {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $actor->isAdmin() && $locked->buyer_id !== $actor->id) {
                throw new BusinessRuleException('forbidden', 'You do not own this purchase order.', 403);
            }

            $remaining = $locked->authorized_quota - $locked->generated_count;
            if ($count > $remaining) {
                throw BusinessRuleException::quotaExceeded($count, max(0, $remaining));
            }

            $start = $this->resolveStartUnit($locked, $factoryId, $machineId, $batchId, $input['unit_start'] ?? null, $count);

            if ($start + $count - 1 > 999999) {
                throw new BusinessRuleException('unit_range_exhausted', 'This batch has no room left under the 6-digit unit limit.');
            }

            $now = now();
            $rows = [];
            $units = [];

            for ($i = 0; $i < $count; $i++) {
                $unitNumber = $start + $i;
                $outer = $this->crypto->formatOuterSerial($factoryId, $machineId, $batchId, $unitNumber, $prodDate, $expDate, $dppDigest ? $keyVersion : null, $dppDigest);
                $inner = $this->crypto->sign($outer);
                $unitHash = $this->crypto->unitHash($outer, $inner);

                $rows[] = [
                    'po_id' => $locked->id,
                    'outer_serial' => $outer,
                    'inner_serial' => $inner,
                    'unit_hash' => $unitHash,
                    'factory_id' => $factoryId,
                    'machine_id' => $machineId,
                    'batch_id' => $batchId,
                    'unit_number' => $unitNumber,
                    'prod_date' => $prodDate->toDateString(),
                    'exp_date' => $expDate->toDateString(),
                    'status' => 'generated',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $units[] = [
                    'outer_serial' => $outer,
                    'inner_serial' => $inner,
                    'unit_hash' => $unitHash,
                ];
            }

            if (count($rows) !== $count) {
                throw new BusinessRuleException('generation_failed', 'Serial count mismatch. No units were generated.');
            }

            try {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('serial_registry')->insert($chunk);
                }
            } catch (Throwable $e) {
                throw new BusinessRuleException(
                    'serial_conflict',
                    'One or more serial numbers already exist for this factory/machine/batch range. Choose another unit start.',
                    409
                );
            }

            $allHashes = SerialRegistry::query()
                ->where('po_id', $locked->id)
                ->orderBy('id')
                ->pluck('unit_hash')
                ->all();
            $root = $this->merkle->root($allHashes);

            $locked->generated_count = $locked->generated_count + $count;
            $locked->merkle_root_hash = $root;
            $locked->save();

            return [
                'order' => $locked->fresh(['buyer']),
                'count' => $count,
                'unit_start' => $start,
                'unit_end' => $start + $count - 1,
                'merkle_root' => $root,
                'units' => $units,
            ];
        }, 3);
    }

    private function resolveStartUnit(
        PurchaseOrder $order,
        string $factoryId,
        string $machineId,
        string $batchId,
        mixed $requested,
        int $count,
    ): int {
        if ($requested !== null && $requested !== '') {
            $start = (int) $requested;

            if ($start < 1 || $start > 999999) {
                throw new BusinessRuleException('invalid_unit_start', 'Unit start must be between 1 and 999999.');
            }

            $exists = SerialRegistry::query()
                ->forBatch($factoryId, $machineId, $batchId)
                ->whereBetween('unit_number', [$start, min(999999, $start + $count - 1)])
                ->exists();

            if ($exists) {
                throw new BusinessRuleException('unit_conflict', 'The requested unit range overlaps existing serials.', 409);
            }

            return $start;
        }

        $max = (int) (SerialRegistry::query()
            ->forBatch($factoryId, $machineId, $batchId)
            ->orderByDesc('unit_number')
            ->lockForUpdate()
            ->value('unit_number') ?? 0);

        return $max + 1;
    }
}
