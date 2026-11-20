<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Models\CartonUnit;
use App\Models\PurchaseOrder;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ProvenanceGraphService
{
    /**
     * @return array<string, mixed>
     */
    public function sync(): array
    {
        $counts = [
            'factories' => DB::table('factories')->count(),
            'purchase_orders' => DB::table('purchase_orders')->count(),
            'carton_aggregations' => DB::table('carton_aggregations')->count(),
            'serial_registry' => DB::table('serial_registry')->count(),
            'scan_logs' => DB::table('scan_logs')->count(),
        ];

        $uri = (string) config('triproof.graph.uri', '');
        if ($uri === '') {
            return ['driver' => 'sql_recursive_cte', 'synced' => false, 'counts' => $counts];
        }

        try {
            $this->cypher(
                "UNWIND range(1, 1) AS ignored\n"
                ."MERGE (g:GraphState {id: 'triproof'}) SET g.last_sync = datetime(), g.serial_count = \$serial_count",
                ['serial_count' => $counts['serial_registry']]
            );

            return ['driver' => 'neo4j', 'synced' => true, 'counts' => $counts];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'driver' => 'sql_recursive_cte',
                'synced' => false,
                'counts' => $counts,
                'fallback_reason' => 'Neo4j unavailable; used deterministic SQL recursive CTE.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function recallBlastRadius(string $batchIdOrSerial, int $hops = 3): array
    {
        $hops = max(1, min(12, $hops));

        $serial = SerialRegistry::query()->where('outer_serial', $batchIdOrSerial)->first();
        $batchId = $serial?->batch_id ?? $batchIdOrSerial;

        $rows = DB::select(
            'WITH RECURSIVE lineage AS (
                SELECT id, outer_serial, rework_parent_serial, batch_id, status, 0 AS depth
                FROM serial_registry
                WHERE batch_id = ?
                UNION ALL
                SELECT sr.id, sr.outer_serial, sr.rework_parent_serial, sr.batch_id, sr.status, lineage.depth + 1
                FROM serial_registry sr
                INNER JOIN lineage ON sr.rework_parent_serial = lineage.outer_serial
                WHERE lineage.depth < ?
            )
            SELECT id, outer_serial, rework_parent_serial, batch_id, status, depth
            FROM lineage
            ORDER BY depth, id
            LIMIT 1000',
            [$batchId, $hops]
        );

        $nodes = [];
        $edges = [];
        foreach ($rows as $row) {
            $nodes[$row->outer_serial] = [
                'id' => $row->outer_serial,
                'type' => 'serial',
                'batch_id' => $row->batch_id,
                'status' => $row->status,
                'depth' => (int) $row->depth,
            ];
            if ($row->rework_parent_serial) {
                $edges[] = [
                    'from' => $row->rework_parent_serial,
                    'to' => $row->outer_serial,
                    'type' => 'rework_child',
                ];
            }
        }

        $cartons = CartonUnit::query()
            ->whereIn('serial_registry_id', array_column($rows, 'id'))
            ->with('carton')
            ->limit(500)
            ->get();

        foreach ($cartons as $unit) {
            if (! $unit->carton || ! $unit->serial) {
                continue;
            }
            $cartonId = 'carton:'.$unit->carton->carton_serial;
            $nodes[$cartonId] = [
                'id' => $cartonId,
                'type' => 'carton',
                'status' => $unit->carton->status,
            ];
            $edges[] = ['from' => $cartonId, 'to' => $unit->serial->outer_serial, 'type' => 'contains'];
        }

        return [
            'query' => $batchIdOrSerial,
            'batch_id' => $batchId,
            'hops' => $hops,
            'driver' => $this->graphDriver(),
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'node_count' => count($nodes),
            'edge_count' => count($edges),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cloneClusterSubgraph(string $serial): array
    {
        $seed = SerialRegistry::query()->where('outer_serial', $serial)->first();

        if (! $seed) {
            return ['root' => $serial, 'nodes' => [], 'edges' => [], 'driver' => $this->graphDriver()];
        }

        $cloned = SerialRegistry::query()
            ->where('status', SerialStatus::ClonedFlagged)
            ->where('batch_id', $seed->batch_id)
            ->when($seed->factory_id, fn ($query) => $query->where('factory_id', $seed->factory_id))
            ->limit(500)
            ->get(['id', 'outer_serial', 'batch_id', 'status']);

        $devices = ScanLog::query()
            ->where('outer_serial', $seed->outer_serial)
            ->limit(200)
            ->pluck('device_token')
            ->filter()
            ->unique()
            ->values();

        $sharedDeviceSerials = $devices->isEmpty()
            ? collect()
            : ScanLog::query()->whereIn('device_token', $devices->all())->limit(500)->pluck('outer_serial')->unique()->values();

        $nodes = [];
        $edges = [];

        foreach ($cloned as $row) {
            $nodes[$row->outer_serial] = [
                'id' => $row->outer_serial,
                'type' => 'serial',
                'status' => $row->status,
                'signal' => 'cloned_status',
            ];
            $edges[] = ['from' => $seed->outer_serial, 'to' => $row->outer_serial, 'type' => 'shared_batch'];
        }

        foreach ($sharedDeviceSerials as $outerSerial) {
            $nodes[$outerSerial] = [
                'id' => $outerSerial,
                'type' => 'serial',
                'status' => 'scan_linked',
                'signal' => 'shared_device',
            ];
            $edges[] = ['from' => $seed->outer_serial, 'to' => $outerSerial, 'type' => 'shared_device'];
        }

        return [
            'root' => $seed->outer_serial,
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'driver' => $this->graphDriver(),
            'node_count' => count($nodes),
            'edge_count' => count($edges),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(User $user): array
    {
        $poIds = PurchaseOrder::query()->visibleTo($user)->select('purchase_orders.id');
        $serial = SerialRegistry::query()
            ->whereIn('po_id', $poIds)
            ->latest()
            ->first(['id', 'outer_serial', 'batch_id']);

        if (! $serial) {
            return ['nodes' => [], 'edges' => [], 'node_count' => 0, 'edge_count' => 0, 'driver' => $this->graphDriver()];
        }

        return $this->recallBlastRadius($serial->outer_serial, 4);
    }

    private function graphDriver(): string
    {
        return (string) config('triproof.graph.uri', '') !== '' ? 'neo4j' : 'sql_recursive_cte';
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function cypher(string $statement, array $parameters = []): void
    {
        $uri = rtrim((string) config('triproof.graph.uri'), '/');
        $database = (string) config('triproof.graph.database', 'neo4j');
        $url = str_contains($uri, '/db/') ? $uri : $uri.'/db/'.$database.'/tx/commit';

        Http::withBasicAuth(
            (string) config('triproof.graph.user', 'neo4j'),
            (string) config('triproof.graph.password', '')
        )
            ->timeout((int) config('triproof.graph.timeout', 10))
            ->throw()
            ->post($url, [
                'statements' => [[
                    'statement' => $statement,
                    'parameters' => (object) $parameters,
                ]],
            ]);
    }
}
