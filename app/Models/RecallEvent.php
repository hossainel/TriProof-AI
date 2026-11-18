<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecallEvent extends Model
{
    protected $fillable = [
        'po_id',
        'factory_id',
        'machine_id',
        'batch_id',
        'affected_count',
        'reason',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'affected_count' => 'integer',
        ];
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
