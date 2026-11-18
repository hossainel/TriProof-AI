<?php

namespace App\Models;

use App\Enums\AnomalyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAnomalyReport extends Model
{
    protected $fillable = [
        'po_id',
        'batch_id',
        'anomaly_type',
        'explanation_en',
        'explanation_bn',
        'affected_units_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'anomaly_type' => AnomalyType::class,
            'affected_units_json' => 'array',
        ];
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
