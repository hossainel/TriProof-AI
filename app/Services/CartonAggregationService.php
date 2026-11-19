<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CartonAggregation;
use App\Models\CartonUnit;
use App\Models\PurchaseOrder;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Crypto\MerkleService;
use App\Services\Crypto\SerialCryptoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CartonAggregationService
{
    public function __construct(
        private readonly MerkleService $merkle,
        private readonly SerialCryptoService $crypto,
    ) {}

    public function aggregate(User $actor, array $input): array
    {
        $po = PurchaseOrder::query()->findOrFail((int) ($input['po_id'] ?? 0));
        Gate::forUser($actor)->authorize('export', $po);

        $factoryId = $this->crypto->normalizeCode((string) ($input['factory_id'] ?? $po->factory_id));
        $batchId = $this->crypto->normalizeCode((string) ($input['batch_id'] ?? ''));
        $serials = array_values(array_unique(array_filter((array) ($input['outer_serial'] ?? []), 'is_string')));

        if (count($serials) < 2 || count($serials) > 100) {
            throw new BusinessRuleException('invalid_carton_size', 'A carton must contain between 2 and 100 units.', 422);
        }
        if ($batchId === '') {
            throw new BusinessRuleException('invalid_batch', 'A batch ID is required.', 422);
        }

        return DB::transaction(function () use ($po, $actor, $factoryId, $batchId, $serials) {
            $units = SerialRegistry::query()
                ->where('po_id', $po->id)
                ->whereIn('outer_serial', $serials)
                ->orderBy('unit_number')
                ->lockForUpdate()
                ->get();

            if ($units->count() !== count($serials)) {
                throw new BusinessRuleException('carton_serial_mismatch', 'Every outer serial must belong to this purchase order.', 422);
            }
            if ($units->contains(fn (SerialRegistry $unit) => $unit->factory_id !== $factoryId || $unit->batch_id !== $batchId)) {
                throw new BusinessRuleException('carton_scope_mismatch', 'All units must share the stated factory and batch.', 422);
            }

            $alreadyAggregated = CartonUnit::query()
                ->whereIn('serial_registry_id', $units->pluck('id'))
                ->exists();
            if ($alreadyAggregated) {
                throw new BusinessRuleException('unit_already_aggregated', 'One or more units already belong to a sealed carton.', 409);
            }

            $version = $this->crypto->keyVersionOf($units->first()->outer_serial);
            $sequence = (int) (CartonAggregation::query()
                ->where('factory_id', $factoryId)->where('batch_id', $batchId)
                ->lockForUpdate()->count() + 1);
            $cartonSerial = sprintf('CTN-%s-%s-%s-%04d', $version, $factoryId, $batchId, $sequence);
            $hashes = $units->pluck('unit_hash')->all();
            $root = $this->merkle->root($hashes);
            $message = "{$cartonSerial}|{$root}|{$units->count()}";
            $signature = $this->crypto->signForVersion($message, $version);

            $carton = CartonAggregation::create([
                'carton_serial' => $cartonSerial,
                'po_id' => $po->id,
                'factory_id' => $factoryId,
                'batch_id' => $batchId,
                'unit_count' => $units->count(),
                'carton_merkle_root' => $root,
                'carton_signature' => $signature,
                'status' => 'sealed',
                'sealed_by_device' => (string) ($input['device_token'] ?? $actor->email),
            ]);

            foreach ($units as $index => $unit) {
                CartonUnit::create([
                    'carton_id' => $carton->id,
                    'serial_registry_id' => $unit->id,
                    'leaf_index' => $index,
                ]);
            }

            $qrPayload = "{$cartonSerial}|{$root}|{$units->count()}|{$signature}";

            return [
                'carton_serial' => $cartonSerial,
                'po_id' => $po->id,
                'factory_id' => $factoryId,
                'batch_id' => $batchId,
                'unit_count' => $units->count(),
                'carton_merkle_root' => $root,
                'carton_signature' => $signature,
                'qr_payload' => $qrPayload,
                'status' => 'sealed',
            ];
        }, 3);
    }

    public function verify(string $cartonSerial): array
    {
        $carton = CartonAggregation::query()->where('carton_serial', $cartonSerial)->with('units.serial')->firstOrFail();
        $version = $this->crypto->keyVersionOf($cartonSerial);
        $message = "{$carton->carton_serial}|{$carton->carton_merkle_root}|{$carton->unit_count}";
        $signatureValid = $this->crypto->verifyForVersion($message, $carton->carton_signature, $version);

        $hashes = $carton->units->pluck('serial.unit_hash')->all();
        $manifest = $carton->units->map(function (CartonUnit $link) use ($hashes) {
            return [
                'leaf_index' => $link->leaf_index,
                'outer_serial' => $link->serial->outer_serial,
                'unit_hash' => $link->serial->unit_hash,
                'merkle_proof' => $this->merkle->proof($hashes, $link->leaf_index),
            ];
        })->all();

        return [
            'carton_serial' => $carton->carton_serial,
            'status' => $carton->status,
            'factory_id' => $carton->factory_id,
            'batch_id' => $carton->batch_id,
            'unit_count' => $carton->unit_count,
            'carton_merkle_root' => $carton->carton_merkle_root,
            'signature_valid' => $signatureValid,
            'merkle_consistent' => $this->merkle->verify($hashes, $carton->carton_merkle_root),
            'manifest' => $manifest,
            'qr_payload' => "{$carton->carton_serial}|{$carton->carton_merkle_root}|{$carton->unit_count}|{$carton->carton_signature}",
        ];
    }
}
