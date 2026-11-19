<?php

namespace App\Services;

use App\Models\BlockchainAnchor;
use App\Models\PurchaseOrder;
use App\Services\Crypto\MerkleService;
use App\Services\Crypto\SerialCryptoService;
use Illuminate\Support\Carbon;

class MerkleAnchorService
{
    public function __construct(
        private readonly MerkleService $merkle,
        private readonly SerialCryptoService $crypto,
    ) {}

    public function anchor(?Carbon $date = null): BlockchainAnchor
    {
        $date = ($date ?? now())->toDateString();
        $orders = PurchaseOrder::query()
            ->whereDate('created_at', '<=', $date)
            ->whereNotNull('merkle_root_hash')
            ->orderBy('id')
            ->get(['id', 'merkle_root_hash']);

        $batchRoot = $this->merkle->root($orders->pluck('merkle_root_hash')->all());
        $poIds = $orders->pluck('id')->all();
        $provider = (string) config('triproof.anchors.provider', 'local_ledger');
        $message = "TRIPROOF-ANCHOR|{$date}|{$batchRoot}|".implode(',', $poIds);

        if ($provider === 'local_ledger' || ! config('services.polygon.rpc_url')) {
            $receipt = 'local:'.$this->crypto->signForVersion($message, $this->crypto->keyVersionOf('K00-K00-K00-000000|19700101|19700101'));
            $status = 'verified';
        } else {
            // External RPC submission is deliberately not faked. Pending anchors are retried until
            // a real transaction receipt is supplied by the configured provider integration.
            $receipt = 'pending:'.hash('sha256', $message);
            $status = 'pending';
        }

        return BlockchainAnchor::query()->updateOrCreate(
            ['anchor_date' => $date, 'batch_merkle_root' => $batchRoot, 'provider' => $provider],
            [
                'po_ids_json' => json_encode($poIds),
                'tx_hash_or_receipt' => $receipt,
                'status' => $status,
            ]
        );
    }

    public function forRoot(string $root): array
    {
        return BlockchainAnchor::query()->where('batch_merkle_root', $root)->latest('anchor_date')->limit(5)->get()->all();
    }
}
