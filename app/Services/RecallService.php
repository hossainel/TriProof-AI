<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\RecallEvent;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecallService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function trigger(User $actor, array $input): array
    {
        $factoryId = strtoupper(trim((string) ($input['factory_id'] ?? '')));
        $machineId = strtoupper(trim((string) ($input['machine_id'] ?? '')));
        $batchId = strtoupper(trim((string) ($input['batch_id'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));

        if (! preg_match('/^[A-Z0-9][A-Z0-9-]{1,15}$/', $factoryId)
            || ! preg_match('/^[A-Z0-9][A-Z0-9-]{0,15}$/', $machineId)
            || ! preg_match('/^[A-Z0-9][A-Z0-9-]{0,15}$/', $batchId)) {
            throw new BusinessRuleException('invalid_batch_scope', 'Provide a valid factory, machine and batch identifier.');
        }

        if ($actor->isFactory() && ! $actor->hasFactoryAccess($factoryId)) {
            throw new BusinessRuleException('forbidden', 'You can only recall batches from your own factory.', 403);
        }

        if ($actor->isFactory() && $actor->factory_id === null) {
            throw new BusinessRuleException('factory_unassigned', 'Your account is not linked to a factory.');
        }

        if (! $actor->isAdmin() && ! $actor->isFactory() && ! $actor->isBuyer()) {
            throw new BusinessRuleException('forbidden', 'You cannot trigger recalls.', 403);
        }

        $base = SerialRegistry::query()->forBatch($factoryId, $machineId, $batchId);

        if ($actor->isBuyer()) {
            $poIds = PurchaseOrder::query()->where('buyer_id', $actor->id)->pluck('id');
            $base->whereIn('po_id', $poIds);
        }

        $batchKey = "{$factoryId}-{$machineId}-{$batchId}";

        return DB::transaction(function () use ($base, $actor, $factoryId, $machineId, $batchId, $batchKey, $reason) {
            $poIds = (clone $base)->select('po_id')->distinct()->pluck('po_id')->all();

            if ($poIds === []) {
                throw new BusinessRuleException('batch_not_found', 'No units were found for that factory, machine and batch.', 404);
            }

            $outers = [];
            $claimedDevices = [];
            $affected = 0;

            $base->select('id', 'outer_serial', 'claimed_device_token')
                ->orderBy('id')
                ->chunkById(1000, function ($serials) use (&$outers, &$claimedDevices, &$affected) {
                    $ids = $serials->pluck('id')->all();

                    foreach ($serials as $serial) {
                        $outers[] = $serial->outer_serial;
                        if ($serial->claimed_device_token) {
                            $claimedDevices[] = $serial->claimed_device_token;
                        }
                        $affected++;
                    }

                    SerialRegistry::query()
                        ->whereIn('id', $ids)
                        ->update([
                            'status' => SerialStatus::Recalled->value,
                            'updated_at' => now(),
                        ]);
                });

            if ($affected === 0) {
                throw new BusinessRuleException('batch_not_found', 'No units were found for that factory, machine and batch.', 404);
            }

            $scanDevices = [];
            foreach (array_chunk($outers, 1000) as $chunk) {
                $tokens = ScanLog::query()
                    ->whereIn('outer_serial', $chunk)
                    ->select('device_token')
                    ->distinct()
                    ->pluck('device_token')
                    ->all();
                array_push($scanDevices, ...$tokens);
            }

            $devices = array_values(array_unique(array_filter(array_merge($claimedDevices, $scanDevices))));
            $notified = $this->notifications->bulkRecallSummaries($devices, $batchKey, $affected, $reason !== '' ? $reason : null);

            $event = RecallEvent::create([
                'po_id' => count($poIds) === 1 ? $poIds[0] : null,
                'factory_id' => $factoryId,
                'machine_id' => $machineId,
                'batch_id' => $batchId,
                'affected_count' => $affected,
                'reason' => $reason !== '' ? $reason : null,
                'triggered_by' => $actor->id,
            ]);

            return [
                'recall_event_id' => $event->id,
                'batch' => $batchKey,
                'affected_units' => $affected,
                'devices_notified' => $notified,
                'triggered_at' => $event->created_at?->toIso8601String(),
            ];
        });
    }
}
