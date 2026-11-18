<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePurchaseOrderRequest;
use App\Http\Requests\GenerateBatchRequest;
use App\Models\PurchaseOrder;
use App\Services\BatchExportService;
use App\Services\BatchGenerationService;
use App\Services\Crypto\MerkleService;
use App\Services\PurchaseOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = PurchaseOrder::query()
            ->visibleTo($request->user())
            ->with('buyer:id,name,email')
            ->withCount([
                'serials as total_serials',
                'serials as packed_count' => fn ($q) => $q->where('status', 'packed'),
                'serials as claimed_count' => fn ($q) => $q->where('status', 'claimed_genuine'),
                'serials as clone_count' => fn ($q) => $q->where('status', 'cloned_flagged'),
                'serials as recalled_count' => fn ($q) => $q->where('status', 'recalled'),
            ])
            ->latest()
            ->paginate(50);

        return ApiResponse::success([
            'orders' => collect($orders->items())->map(fn (PurchaseOrder $order) => $this->orderPayload($order))->all(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function store(CreatePurchaseOrderRequest $request, PurchaseOrderService $service): JsonResponse
    {
        $order = $service->create($request->user(), $request->validated());

        return ApiResponse::success(
            ['order' => $this->orderPayload($order->load('buyer'))],
            'Purchase order created with a quota-locked signing contract.',
            201
        );
    }

    public function generateBatch(
        GenerateBatchRequest $request,
        BatchGenerationService $service,
    ): JsonResponse {
        $order = PurchaseOrder::query()->findOrFail((int) $request->input('po_id'));
        Gate::authorize('generate', $order);

        $result = $service->generate($order, $request->user(), $request->validated());
        $includeUnits = $request->has('include_units')
            ? $request->boolean('include_units')
            : $result['count'] <= 1000;

        $data = [
            'order' => $this->orderPayload($result['order']),
            'generated_count' => $result['count'],
            'unit_start' => $result['unit_start'],
            'unit_end' => $result['unit_end'],
            'merkle_root' => $result['merkle_root'],
            'units_included' => $includeUnits,
            'exports' => [
                'csv' => route('api.orders.export', [
                    'po' => $order->id,
                    'format' => 'csv',
                    'batch' => $request->input('batch_id'),
                ]),
                'pdf' => route('api.orders.export', [
                    'po' => $order->id,
                    'format' => 'pdf',
                    'batch' => $request->input('batch_id'),
                ]),
            ],
        ];

        if ($includeUnits) {
            $data['units'] = $result['units'];
        }

        return ApiResponse::success(
            $data,
            "Exactly {$result['count']} quota-locked Ed25519 serial pair(s) generated.",
            201
        );
    }

    public function export(
        Request $request,
        PurchaseOrder $po,
        BatchExportService $exports,
    ): StreamedResponse|Response|JsonResponse {
        Gate::authorize('export', $po);

        $format = strtolower((string) $request->query('format', 'csv'));
        $batch = $request->query('batch');

        if ($batch !== null && (! is_string($batch) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,15}$/', $batch))) {
            throw new BusinessRuleException('invalid_batch', 'The batch filter is invalid.');
        }

        if (! in_array($format, ['csv', 'pdf'], true)) {
            throw new BusinessRuleException('invalid_format', 'Export format must be csv or pdf.');
        }

        return $format === 'csv'
            ? $exports->csv($po, $batch ? strtoupper($batch) : null)
            : $exports->pdf($po, $batch ? strtoupper($batch) : null);
    }

    public function merkle(Request $request, PurchaseOrder $po, MerkleService $merkle): JsonResponse
    {
        Gate::authorize('view', $po);

        $hashes = $po->serials()->orderBy('id')->pluck('unit_hash')->all();
        $recomputed = empty($hashes) ? null : $merkle->root($hashes);
        $stored = $po->merkle_root_hash;

        return ApiResponse::success([
            'po_id' => $po->id,
            'po_number' => $po->po_number,
            'algorithm' => 'SHA-256 binary Merkle tree (0x01 domain separation, odd-node promotion)',
            'stored_root' => $stored,
            'recomputed_root' => $recomputed,
            'matches' => $stored !== null && $recomputed !== null && hash_equals($stored, $recomputed),
            'leaf_count' => count($hashes),
            'generated_count' => $po->generated_count,
            'consistent' => count($hashes) === $po->generated_count,
            'blockchain_ready' => true,
            'anchoring_status' => 'pending_phase_2',
            'verified_at' => now()->toIso8601String(),
        ]);
    }

    private function orderPayload(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'po_number' => $order->po_number,
            'buyer' => $order->relationLoaded('buyer') ? [
                'id' => $order->buyer?->id,
                'name' => $order->buyer?->name,
                'email' => $order->buyer?->email,
            ] : null,
            'factory_id' => $order->factory_id,
            'authorized_quota' => $order->authorized_quota,
            'generated_count' => $order->generated_count,
            'remaining_quota' => $order->remainingQuota(),
            'factory_expiry_days' => $order->factory_expiry_days,
            'retail_expiry_days' => $order->retail_expiry_days,
            'factory_deadline' => $order->factoryDeadline()->toIso8601String(),
            'merkle_root_hash' => $order->merkle_root_hash,
            'counts' => [
                'serials' => $order->total_serials ?? null,
                'packed' => $order->packed_count ?? null,
                'claimed_genuine' => $order->claimed_count ?? null,
                'cloned_flagged' => $order->clone_count ?? null,
                'recalled' => $order->recalled_count ?? null,
            ],
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
