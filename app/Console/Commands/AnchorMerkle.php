<?php

namespace App\Console\Commands;

use App\Services\MerkleAnchorService;
use Illuminate\Console\Command;

class AnchorMerkle extends Command
{
    protected $signature = 'triproof:anchor-merkle';

    protected $description = 'Anchor the current batch Merkle root with the configured provider';

    public function handle(MerkleAnchorService $anchors): int
    {
        $anchor = $anchors->anchor();

        $this->info("Anchor {$anchor->status}: {$anchor->batch_merkle_root}");
        $this->line($anchor->tx_hash_or_receipt);

        return self::SUCCESS;
    }
}
