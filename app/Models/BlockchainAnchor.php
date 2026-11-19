<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockchainAnchor extends Model
{
    use HasFactory;

    protected $fillable = [
        'anchor_date', 'batch_merkle_root', 'po_ids_json', 'provider',
        'tx_hash_or_receipt', 'status',
    ];

    protected function casts(): array
    {
        return ['anchor_date' => 'date'];
    }

    public function poIds(): array
    {
        return json_decode($this->po_ids_json, true) ?: [];
    }
}
