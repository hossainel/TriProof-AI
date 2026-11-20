<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\SerialRegistry;
use App\Models\User;

class PackingService
{
    public function pack(User $actor, array $input): array
    {
        $order = PurchaseOrder::query()->find((int) ($input['po_id'] ?? 0));

        if (! $order) {
            throw new BusinessRuleException('order_not_found', 'Purchase order not found.', 404);
        }

        if (! $actor->isAdmin() && ! ($actor->isFactory() && $actor->factory_id === $order->factory_id)) {
            throw new BusinessRuleException('forbidden', 'Only the assigned factory QA team can pack this order.', 403);
        }

        $machineId = strtoupper(trim((string) ($input['machine_id'] ?? '')));
        $batchId = strtoupper(trim((string) ($input['batch_id'] ?? '')));

        if (! preg_match('/^[A-Z0-9][A-Z0-9-]{0,15}$/', $machineId)
            || ! preg_match('/^[A-Z0-9][A-Z0-9-]{0,15}$/', $batchId)) {
            throw new BusinessRuleException('invalid_batch_scope', 'Provide a valid machine and batch ID.');
        }

        $query = SerialRegistry::query()
            ->where('po_id', $order->id)
            ->forBatch($order->factory_id, $machineId, $batchId)
            ->where('status', SerialStatus::Generated->value);

        $start = isset($input['unit_start']) && $input['unit_start'] !== '' ? (int) $input['unit_start'] : null;
        $end = isset($input['unit_end']) && $input['unit_end'] !== '' ? (int) $input['unit_end'] : null;

        if ($start !== null && $end !== null) {
            if ($start < 1 || $end < $start) {
                throw new BusinessRuleException('invalid_unit_range', 'Unit range is invalid.');
            }
            $query->whereBetween('unit_number', [$start, $end]);
        } elseif ($start !== null) {
            $query->where('unit_number', '>=', $start);
        } elseif ($end !== null) {
            $query->where('unit_number', '<=', $end);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $available = SerialRegistry::query()
                ->where('po_id', $order->id)
                ->where('status', SerialStatus::Generated->value)
                ->select('machine_id', 'batch_id')
                ->selectRaw('COUNT(*) as units')
                ->selectRaw('MIN(unit_number) as min_unit')
                ->selectRaw('MAX(unit_number) as max_unit')
                ->groupBy('machine_id', 'batch_id')
                ->orderBy('machine_id')
                ->orderBy('batch_id')
                ->get();

            if ($available->isEmpty()) {
                $message = __('triproof.validation.pack_all_packed', [
                    'po' => $order->po_number,
                ]);
            } else {
                $batches = $available->map(fn ($row) => __('triproof.validation.pack_available_batch', [
                    'machine' => $row->machine_id,
                    'batch' => $row->batch_id,
                    'count' => $row->units,
                    'min' => $row->min_unit,
                    'max' => $row->max_unit,
                ]))->implode('; ');

                $message = __('triproof.validation.pack_no_match', [
                    'po' => $order->po_number,
                    'batches' => $batches,
                ]);
            }

            throw new BusinessRuleException('nothing_to_pack', $message);
        }

        $query->update([
            'status' => SerialStatus::Packed->value,
            'updated_at' => now(),
        ]);

        return [
            'po_id' => $order->id,
            'batch' => "{$order->factory_id}-{$machineId}-{$batchId}",
            'packed_count' => $count,
        ];
    }
}
