<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\BatchExportService;
use App\Services\BatchGenerationService;
use App\Services\Crypto\MerkleService;
use App\Services\PackingService;
use App\Services\PurchaseOrderService;
use App\Services\RecallService;
use App\Services\ReworkService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function store(Request $request, PurchaseOrderService $service): RedirectResponse
    {
        $data = $request->validate([
            'po_number' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'buyer_id' => ['nullable', 'integer', 'exists:users,id'],
            'factory_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,31}$/'],
            'authorized_quota' => ['required', 'integer', 'min:1', 'max:1000000'],
            'factory_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'retail_expiry_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        if ($request->user()->isAdmin() && empty($data['buyer_id'])) {
            return back()->withErrors(['buyer_id' => __('triproof.validation.buyer_required')])->withInput();
        }

        try {
            $order = $service->create($request->user(), $data);
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['order' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dashboard')
            ->with('success', __('triproof.flash.order_created', ['po' => $order->po_number]));
    }

    public function generate(
        Request $request,
        PurchaseOrder $po,
        BatchGenerationService $service,
    ): RedirectResponse {
        Gate::authorize('generate', $po);

        $max = (int) config('triproof.exports.max_generate', 50000);
        $data = $request->validate([
            'machine_id' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,15}$/'],
            'batch_id' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,15}$/'],
            'unit_start' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'prod_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'count' => ['required', 'integer', 'min:1', "max:{$max}"],
        ]);

        try {
            $result = $service->generate($po, $request->user(), $data);
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['generate' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dashboard')
            ->with('success', __('triproof.flash.generated', [
                'count' => $result['count'],
                'batch' => $po->factory_id.'-'.strtoupper((string) $data['machine_id']).'-'.strtoupper((string) $data['batch_id']),
            ]))
            ->with('generated', [
                'po_id' => $po->id,
                'po_number' => $po->po_number,
                'count' => $result['count'],
                'unit_start' => $result['unit_start'],
                'unit_end' => $result['unit_end'],
                'merkle_root' => $result['merkle_root'],
                'batch' => strtoupper((string) $data['batch_id']),
            ]);
    }

    public function pack(
        Request $request,
        PurchaseOrder $po,
        PackingService $service,
    ): RedirectResponse {
        Gate::authorize('pack', $po);

        $data = $request->validate([
            'machine_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'batch_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'unit_start' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'unit_end' => ['nullable', 'integer', 'min:1', 'max:999999', 'gte:unit_start'],
        ]);

        try {
            $result = $service->pack($request->user(), array_merge($data, ['po_id' => $po->id]));
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['pack' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dashboard')
            ->with('success', __('triproof.flash.packed', [
                'count' => $result['packed_count'],
                'batch' => $result['batch'],
            ]));
    }

    public function export(
        Request $request,
        PurchaseOrder $po,
        BatchExportService $exports,
    ): StreamedResponse|Response {
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

    public function qrStickers(
        Request $request,
        PurchaseOrder $po,
        BatchExportService $exports,
    ): Response {
        Gate::authorize('qrStickers', $po);

        $batch = $request->query('batch');
        if ($batch !== null && (! is_string($batch) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,15}$/', $batch))) {
            throw new BusinessRuleException('invalid_batch', 'The batch filter is invalid.');
        }

        $limit = $request->query('limit');
        if ($limit !== null && (! is_scalar($limit) || ! preg_match('/^[0-9]+$/', (string) $limit))) {
            throw new BusinessRuleException('invalid_limit', 'The sticker limit must be a whole number.', 422);
        }

        $response = $exports->qrStickers(
            $po,
            $limit === null ? null : (int) $limit,
            $batch ? strtoupper($batch) : null,
            ! $request->boolean('download'),
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    public function merkle(PurchaseOrder $po, MerkleService $merkle): JsonResponse
    {
        Gate::authorize('view', $po);

        $hashes = $po->serials()->orderBy('id')->pluck('unit_hash')->all();
        $recomputed = $hashes === [] ? null : $merkle->root($hashes);
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

    public function lookup(PurchaseOrder $po): JsonResponse
    {
        Gate::authorize('view', $po);

        $po->load('buyer:id,name,email');

        $batches = $po->serials()
            ->select('factory_id', 'machine_id', 'batch_id')
            ->selectRaw('COUNT(*) as units')
            ->groupBy('factory_id', 'machine_id', 'batch_id')
            ->orderBy('batch_id')
            ->get();

        return ApiResponse::success([
            'order' => [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'buyer' => $po->buyer?->name,
                'factory_id' => $po->factory_id,
                'authorized_quota' => $po->authorized_quota,
                'generated_count' => $po->generated_count,
                'remaining_quota' => $po->remainingQuota(),
                'factory_expiry_days' => $po->factory_expiry_days,
                'retail_expiry_days' => $po->retail_expiry_days,
            ],
            'batches' => $batches,
        ]);
    }

    public function recall(Request $request, RecallService $service): RedirectResponse
    {
        $data = $request->validate([
            'factory_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,31}$/'],
            'machine_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'batch_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = $service->trigger($request->user(), $data);
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['recall' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dashboard')
            ->with('success', __('triproof.flash.recalled', [
                'count' => $result['affected_units'],
                'batch' => $result['batch'],
                'devices' => $result['devices_notified'],
            ]))
            ->with('recall_result', $result);
    }

    public function rework(Request $request, ReworkService $service): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:csv,txt,json'],
        ]);

        $file = $request->file('file');
        if (! $file) {
            return back()->withErrors(['file' => __('triproof.validation.rework_file_required')]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'csv');

        try {
            $entries = $this->parseReworkFile((string) file_get_contents($file->getRealPath()), $extension);
            $result = $service->reconcile($request->user(), $entries);
        } catch (BusinessRuleException $e) {
            return back()->withErrors(['rework' => $e->getMessage()])->withInput();
        }

        $allResults = $result['results'];
        $result['results'] = array_slice($allResults, 0, 100);
        $result['results_truncated'] = count($allResults) > 100;

        return redirect()->route('dashboard')
            ->with('success', __('triproof.flash.rework', [
                'linked' => $result['counts']['linked'],
                'gaps' => $result['counts']['gaps'],
                'rejected' => $result['counts']['rejected'],
            ]))
            ->with('rework_result', $result);
    }

    public function reworkTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['parent_outer_serial', 'child_outer_serial', 'action', 'line_id', 'note']);
            fputcsv($out, [
                'FAC-01-LINE01-000001|20260101|20270101',
                'FAC-01-LINE01-000002|20260101|20270101',
                'rework',
                'LINE-01',
                'Replacement parent label verified against the paper rework log.',
            ]);
            fclose($out);
        }, 'triproof-rework-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function parseReworkFile(string $contents, string $extension): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $trimmed = ltrim($contents);

        if ($trimmed === '') {
            throw new BusinessRuleException('rework_file_empty', 'The rework file is empty.');
        }

        $entries = [];

        if ($extension === 'json' || str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
            $decoded = json_decode($contents, true);

            if (! is_array($decoded)) {
                throw new BusinessRuleException('invalid_rework_json', 'The JSON file is not valid: '.json_last_error_msg());
            }

            $entries = isset($decoded['entries']) && is_array($decoded['entries'])
                ? $decoded['entries']
                : $decoded;
        } else {
            $entries = $this->parseReworkCsv($contents);
        }

        $entries = array_values(array_filter($entries, 'is_array'));

        if ($entries === []) {
            throw new BusinessRuleException('rework_entries_empty', 'No usable rework rows were found in the uploaded file.');
        }

        if (count($entries) > 1000) {
            throw new BusinessRuleException('rework_file_too_large', 'A reconciliation file may contain at most 1000 entries.');
        }

        return $entries;
    }

    private function parseReworkCsv(string $contents): array
    {
        $delimiter = ',';
        $firstLine = strtok($contents, "\n") ?: '';
        $delimiters = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delimiters);
        $delimiter = (string) array_key_first($delimiters);

        if ($delimiters[$delimiter] === 0) {
            $delimiter = ',';
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > 1002) {
                break;
            }
        }
        fclose($stream);

        if ($rows === []) {
            return [];
        }

        $aliases = [
            'parent' => ['parent_outer_serial', 'parent_serial', 'parent', 'old_outer_serial', 'old_serial'],
            'child' => ['child_outer_serial', 'child_serial', 'child', 'new_outer_serial', 'new_serial'],
            'action' => ['action', 'rework_action'],
            'line_id' => ['line_id', 'line', 'station'],
            'note' => ['note', 'notes', 'reason', 'remarks'],
        ];

        $header = array_map(
            fn ($value) => preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $value))),
            $rows[0]
        );

        $mapped = [];
        foreach ($aliases as $key => $names) {
            foreach ($names as $name) {
                $index = array_search($name, $header, true);
                if ($index !== false) {
                    $mapped[$key] = $index;
                    break;
                }
            }
        }

        if (isset($mapped['parent'], $mapped['child'])) {
            $dataRows = array_slice($rows, 1);
        } else {
            $looksLikeData = isset($rows[0][0], $rows[0][1])
                && str_contains((string) $rows[0][0], '|')
                && str_contains((string) $rows[0][1], '|');

            if (! $looksLikeData) {
                throw new BusinessRuleException(
                    'invalid_rework_header',
                    'CSV must include parent_outer_serial and child_outer_serial columns. Download the template for the expected format.'
                );
            }

            $mapped = ['parent' => 0, 'child' => 1];
            $dataRows = $rows;
        }

        $entries = [];
        foreach ($dataRows as $row) {
            $parent = trim((string) ($row[$mapped['parent']] ?? ''));
            $child = trim((string) ($row[$mapped['child']] ?? ''));

            if ($parent === '' && $child === '') {
                continue;
            }

            $entry = [
                'parent_outer_serial' => $parent,
                'child_outer_serial' => $child,
            ];

            foreach (['action', 'line_id', 'note'] as $key) {
                if (isset($mapped[$key])) {
                    $value = trim((string) ($row[$mapped[$key]] ?? ''));
                    if ($value !== '') {
                        $entry[$key] = mb_substr($value, 0, 500);
                    }
                }
            }

            $entries[] = $entry;
        }

        return $entries;
    }
}
