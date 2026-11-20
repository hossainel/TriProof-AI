<?php

namespace App\Services\Mcp;

use App\Enums\SerialStatus;
use App\Models\AiAnomalyReport;
use App\Models\BlockchainAnchor;
use App\Models\CartonUnit;
use App\Models\PurchaseOrder;
use App\Models\RecallEvent;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Services\Crypto\MerkleService;
use App\Services\Crypto\SerialCryptoService;
use Throwable;

class McpToolService
{
    public function __construct(
        private readonly SerialCryptoService $crypto,
        private readonly MerkleService $merkle,
    ) {}

    /**
     * Deterministic 7-stage forensic state machine. No external AI is used.
     *
     * @return array<string, mixed>
     */
    public function verifySerial(string $payloadOrSerial): array
    {
        $outer = $this->crypto->normalizePayload(trim($payloadOrSerial));
        $parsed = $this->crypto->parseOuterSerial($outer);
        $keyVersion = $parsed['key_version'] ?? $this->crypto->keyVersionOf($outer);
        $keyId = hash('sha256', $keyVersion);

        $registry = SerialRegistry::query()->where('outer_serial', $outer)->first();
        $signatureValid = $registry && $registry->inner_serial
            ? $this->crypto->verify($outer, $registry->inner_serial)
            : false;

        $recalled = $registry?->status === SerialStatus::Recalled;
        $cloned = $registry?->status === SerialStatus::ClonedFlagged;
        $expired = $parsed !== null && $parsed['exp_date'] < now()->toDateString();
        $scanCount = ScanLog::query()->where('outer_serial', $outer)->count();

        if (! $signatureValid) {
            $verdict = 'forged_signature';
        } elseif ($recalled) {
            $verdict = 'recalled_batch';
        } elseif ($expired) {
            $verdict = 'expired';
        } elseif ($cloned) {
            $verdict = 'clone_detected';
        } elseif ($registry) {
            $verdict = 'verified_clean';
        } else {
            $verdict = 'unknown_unsigned';
        }

        $stages = [
            ['stage' => 1, 'name' => 'normalize', 'status' => $outer !== '' ? 'pass' : 'fail'],
            ['stage' => 2, 'name' => 'parse_serial', 'status' => $parsed !== null ? 'pass' : 'fail'],
            ['stage' => 3, 'name' => 'resolve_key', 'status' => $keyVersion !== '' ? 'pass' : 'fail', 'key_version' => $keyVersion],
            ['stage' => 4, 'name' => 'verify_signature', 'status' => $signatureValid ? 'pass' : 'fail'],
            ['stage' => 5, 'name' => 'registry_lookup', 'status' => $registry ? 'pass' : 'skip'],
            ['stage' => 6, 'name' => 'recall_expiry_clone', 'status' => ($recalled || $expired || $cloned) ? 'flag' : 'pass'],
            ['stage' => 7, 'name' => 'scan_history', 'status' => 'pass', 'scan_count' => $scanCount],
        ];

        return [
            'payload' => $payloadOrSerial,
            'outer_serial' => $outer,
            'parsed' => $parsed,
            'key_version' => $keyVersion,
            'key_id' => $keyId,
            'signature_valid' => $signatureValid,
            'registered' => $registry !== null,
            'status' => $registry?->status?->value,
            'verdict' => $verdict,
            'authentic' => $signatureValid && $registry !== null && ! $recalled && ! $expired && ! $cloned,
            'scan_count' => $scanCount,
            'stages' => $stages,
            'deterministic' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getBatchAnomalies(string $batchId, ?string $severity = null): array
    {
        $query = AiAnomalyReport::query()->where('batch_id', $batchId)->latest();

        if ($severity !== null && in_array(strtolower($severity), ['low', 'medium', 'high'], true)) {
            $wanted = strtolower($severity);
            $query->where(function ($builder) use ($wanted): void {
                $builder->whereJsonContains('affected_units_json->ai_severity', $wanted)
                    ->orWhere('explanation_en', 'like', '%'.$wanted.'%');
            });
        }

        $reports = $query->limit(50)->get()->map(function (AiAnomalyReport $report): array {
            $affected = $report->affected_units_json ?? [];
            $aiSeverity = is_array($affected) ? ($affected['ai_severity'] ?? null) : null;

            return [
                'id' => $report->id,
                'detector' => $report->anomaly_type->value,
                'severity' => is_string($aiSeverity) ? strtolower($aiSeverity) : 'medium',
                'status' => $report->status,
                'batch_id' => $report->batch_id,
                'po_id' => $report->po_id,
                'summary_en' => $report->explanation_en,
                'summary_bn' => $report->explanation_bn,
                'action_en' => is_array($affected) ? ($affected['recommended_action_en'] ?? null) : null,
                'action_bn' => is_array($affected) ? ($affected['recommended_action_bn'] ?? null) : null,
            ];
        })->all();

        return [
            'batch_id' => $batchId,
            'severity_filter' => $severity,
            'count' => count($reports),
            'reports' => $reports,
            'deterministic' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function traceReworkLineage(string $serial): array
    {
        $seed = SerialRegistry::query()->where('outer_serial', $serial)->first();

        if (! $seed) {
            return ['root' => $serial, 'nodes' => [], 'edges' => [], 'truncated' => false];
        }

        $nodes = [];
        $edges = [];

        $addNode = function (SerialRegistry $row, string $relation) use (&$nodes): void {
            $nodes[$row->outer_serial] = [
                'id' => $row->outer_serial,
                'type' => 'serial',
                'relation' => $relation,
                'status' => $row->status?->value,
                'po_id' => $row->po_id,
                'unit_number' => $row->unit_number,
            ];
        };

        $addNode($seed, 'root');

        $cursor = $seed;
        $ancestorSteps = 0;
        while ($cursor->rework_parent_serial && $ancestorSteps < 50) {
            $parent = SerialRegistry::query()->where('outer_serial', $cursor->rework_parent_serial)->first();
            if (! $parent) {
                break;
            }
            $addNode($parent, 'ancestor');
            $edges[] = ['from' => $parent->outer_serial, 'to' => $cursor->outer_serial, 'type' => 'rework'];
            $cursor = $parent;
            $ancestorSteps++;
        }

        $queue = [$seed->outer_serial];
        $visited = [$seed->outer_serial => true];
        $childCount = 0;

        while ($queue !== [] && $childCount < 200) {
            $current = array_shift($queue);
            $children = SerialRegistry::query()->where('rework_parent_serial', $current)->limit(100)->get();

            foreach ($children as $child) {
                if (isset($visited[$child->outer_serial])) {
                    continue;
                }
                $visited[$child->outer_serial] = true;
                $addNode($child, 'descendant');
                $edges[] = ['from' => $current, 'to' => $child->outer_serial, 'type' => 'rework'];
                $queue[] = $child->outer_serial;
                $childCount++;
            }
        }

        $cartons = CartonUnit::query()
            ->whereIn('serial_registry_id', array_map(
                fn (SerialRegistry $row) => $row->id,
                SerialRegistry::query()->whereIn('outer_serial', array_keys($nodes))->get(['id'])->all()
            ))
            ->with('carton')
            ->limit(100)
            ->get();

        foreach ($cartons as $unit) {
            $carton = $unit->carton;
            if (! $carton) {
                continue;
            }
            $nodes['carton:'.$carton->carton_serial] = [
                'id' => 'carton:'.$carton->carton_serial,
                'type' => 'carton',
                'relation' => 'container',
                'status' => $carton->status,
                'unit_count' => $carton->unit_count,
            ];
            $edges[] = [
                'from' => 'carton:'.$carton->carton_serial,
                'to' => $unit->serial?->outer_serial ?? $serial,
                'type' => 'contained_in',
            ];
        }

        return [
            'root' => $seed->outer_serial,
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'truncated' => $ancestorSteps >= 50 || $childCount >= 200,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkRecallStatus(string $batchIdOrSerial): array
    {
        $parsed = $this->crypto->parseOuterSerial($batchIdOrSerial);
        $batchId = $parsed['batch_id'] ?? $batchIdOrSerial;

        $recalls = RecallEvent::query()
            ->where('batch_id', $batchId)
            ->when($parsed !== null, fn ($query) => $query
                ->where('factory_id', $parsed['factory_id'])
                ->where('machine_id', $parsed['machine_id']))
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (RecallEvent $event) => [
                'id' => $event->id,
                'po_id' => $event->po_id,
                'batch_id' => $event->batch_id,
                'affected_count' => $event->affected_count,
                'reason' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all();

        $serials = SerialRegistry::query()
            ->when($parsed !== null, fn ($query) => $query->where('outer_serial', $this->crypto->normalizePayload($batchIdOrSerial)))
            ->when($parsed === null, fn ($query) => $query->where('batch_id', $batchId))
            ->limit(5000)
            ->get(['outer_serial', 'status']);

        $recalled = $serials->where('status', SerialStatus::Recalled)->count();
        $cloned = $serials->where('status', SerialStatus::ClonedFlagged)->count();

        return [
            'query' => $batchIdOrSerial,
            'batch_id' => $batchId,
            'active' => $recalls !== [] || $recalled > 0,
            'blacklisted' => $recalled > 0 || $cloned > 0,
            'recalled_serials' => $recalled,
            'cloned_serials' => $cloned,
            'recall_events' => $recalls,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMerkleProof(int $scanLogId): array
    {
        $scan = ScanLog::query()->find($scanLogId);

        if (! $scan) {
            return ['found' => false, 'error' => 'Scan log not found.'];
        }

        $serial = SerialRegistry::query()->where('outer_serial', $scan->outer_serial)->first();
        if (! $serial || ! $serial->po_id) {
            return ['found' => false, 'error' => 'The scanned serial is not linked to a purchase order.'];
        }

        $order = PurchaseOrder::query()->find($serial->po_id);
        if (! $order) {
            return ['found' => false, 'error' => 'Purchase order not found.'];
        }

        $rows = $order->serials()->orderBy('id')->get(['id', 'unit_hash']);
        $hashes = $rows->pluck('unit_hash')->all();
        $index = $rows->search(fn ($row) => (int) $row->id === (int) $serial->id);

        if ($index === false || $hashes === []) {
            return ['found' => false, 'error' => 'Serial is not part of the order Merkle tree.'];
        }

        try {
            $proof = $this->merkle->proof($hashes, (int) $index);
            $root = $this->merkle->root($hashes);
        } catch (Throwable) {
            return ['found' => false, 'error' => 'The Merkle proof could not be computed.'];
        }

        $anchor = BlockchainAnchor::query()
            ->where('batch_merkle_root', $root)
            ->latest('anchor_date')
            ->first();

        return [
            'found' => true,
            'scan_log_id' => $scan->id,
            'outer_serial' => $scan->outer_serial,
            'po_number' => $order->po_number,
            'leaf_index' => (int) $index,
            'leaf_hash' => $hashes[$index],
            'proof' => $proof,
            'merkle_root' => $root,
            'proof_valid' => $root !== null && $this->merkle->verifyProof((string) $hashes[$index], $proof, $root),
            'proof_verified_at' => now()->toIso8601String(),
            'anchor' => $anchor ? [
                'anchor_date' => $anchor->anchor_date?->toDateString(),
                'provider' => $anchor->provider,
                'receipt' => $anchor->tx_hash_or_receipt,
                'status' => $anchor->status,
            ] : null,
            'deterministic' => true,
        ];
    }
}
