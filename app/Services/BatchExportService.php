<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\SerialRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
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
