<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\SerialRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchExportService
{
    public function csv(PurchaseOrder $order, ?string $batchId = null): StreamedResponse
    {
        $filename = $this->filename($order, $batchId).'.csv';

        return response()->streamDownload(function () use ($order, $batchId) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'outer_serial',
                'inner_serial',
                'unit_hash',
                'factory_id',
                'machine_id',
                'batch_id',
                'unit_number',
                'prod_date',
                'exp_date',
                'status',
            ]);

            $query = $order->serials()->orderBy('unit_number');
            if ($batchId) {
                $query->where('batch_id', $batchId);
            }

            $query->chunk(1000, function ($serials) use ($out) {
                foreach ($serials as $serial) {
                    fputcsv($out, [
                        $serial->outer_serial,
                        $serial->inner_serial,
                        $serial->unit_hash,
                        $serial->factory_id,
                        $serial->machine_id,
                        $serial->batch_id,
                        $serial->unit_number,
                        $serial->prod_date->toDateString(),
                        $serial->exp_date->toDateString(),
                        $serial->status->value,
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function pdf(PurchaseOrder $order, ?string $batchId = null): Response
    {
        $baseQuery = $order->serials();
        if ($batchId) {
            $baseQuery->where('batch_id', $batchId);
        }

        $total = (clone $baseQuery)->count();
        $max = (int) config('triproof.exports.max_pdf_units', 20000);

        if ($total === 0) {
            throw new BusinessRuleException('empty_batch', 'Generate serials before exporting a batch package.');
        }

        if ($total > $max) {
            throw new BusinessRuleException(
                'pdf_too_large',
                "This batch has {$total} units; PDF export supports up to {$max}. Use the CSV export for larger batches.",
                422,
                ['count' => $total, 'max' => $max]
            );
        }

        $order->loadMissing(['buyer']);
        $serials = $baseQuery->orderBy('unit_number')->get();

        $pdf = Pdf::loadView('exports.batch-pdf', [
            'order' => $order,
            'serials' => $serials,
            'generatedAt' => now(),
            'publicKey' => (string) config('triproof.crypto.public_key'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($order, $batchId).'.pdf');
    }

    /**
     * Render paired outer/inner QR stickers. Serials are signed exactly as generated and the PNGs
     * are produced in-process with GD: no font, renderer or QR HTTP service is contacted.
     */
    public function qrStickerViewData(
        PurchaseOrder $order,
        ?int $limit = null,
        ?string $batchId = null,
    ): array {
        $query = $order->serials();
        if ($batchId) {
            $query->where('batch_id', $batchId);
        }

        $max = max(1, (int) config('triproof.max_pdf_units', config('triproof.exports.max_pdf_units', 20000)));
        if ($limit !== null && ($limit < 1 || $limit > $max)) {
            throw new BusinessRuleException(
                'invalid_limit',
                "The sticker limit must be between 1 and {$max}.",
                422,
                ['limit' => $limit, 'max' => $max]
            );
        }

        $selected = (clone $query)->orderBy('unit_number')->limit($limit ?? $max)->get();
        if ($selected->isEmpty()) {
            throw new BusinessRuleException('empty_batch', 'Generate serials before printing QR stickers.');
        }

        $total = (clone $query)->count();

        return [
            'serials' => $selected->map(fn (SerialRegistry $serial) => [
                'outer_serial' => $serial->outer_serial,
                'inner_serial' => $serial->inner_serial,
                'unit_hash' => $serial->unit_hash,
                'factory_id' => $serial->factory_id,
                'machine_id' => $serial->machine_id,
                'batch_id' => $serial->batch_id,
                'exp_date' => $serial->exp_date?->toDateString() ?? '—',
                'outer_url' => rtrim((string) config('app.url'), '/').'/v/'.$serial->outer_serial,
                'outer_qr' => $this->qr(rtrim((string) config('app.url'), '/').'/v/'.$serial->outer_serial, EccLevel::M, 16),
                'inner_qr' => $this->qr($serial->inner_serial, EccLevel::Q, 14),
            ])->all(),
            'printedPairs' => $selected->count(),
            'totalPairs' => $total,
            'truncated' => $total > $selected->count(),
            'merkle' => $order->merkle_root_hash ?: '—',
            'printedAt' => now(),
        ];
    }

    public function qrStickers(
        PurchaseOrder $order,
        ?int $limit = null,
        ?string $batchId = null,
        bool $inline = true,
    ): Response {
        $data = $this->qrStickerViewData($order, $limit, $batchId);
        $pdf = Pdf::loadView('exports.qr-labels-pdf', array_merge(['order' => $order], $data))
            ->setPaper('a4', 'portrait');

        $filename = 'triproof-qr-stickers-'.$order->po_number.'.pdf';

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    private function qr(string $data, int $ecc, int $scale = 12): string
    {
        $png = (new QRCode(new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => $ecc,
            'quietzoneSize' => 4,
            'scale' => $scale,
            'outputBase64' => false,
        ])))->render($data);

        if (! is_string($png) || ! str_starts_with($png, "\x89PNG")) {
            throw new BusinessRuleException('qr_render_failed', 'Unable to render a local QR sticker.', 500);
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    public function filename(PurchaseOrder $order, ?string $batchId = null): string
    {
        $serial = SerialRegistry::query()
            ->where('po_id', $order->id)
            ->when($batchId, fn ($q) => $q->where('batch_id', $batchId))
            ->select('factory_id', 'machine_id', 'batch_id')
            ->orderBy('id')
            ->first();

        $scope = $serial
            ? "{$serial->factory_id}-{$serial->machine_id}-{$serial->batch_id}"
            : $order->factory_id;

        return preg_replace('/[^A-Za-z0-9._-]/', '_', "triproof-{$order->po_number}-{$scope}");
    }
}
