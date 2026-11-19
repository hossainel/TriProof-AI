<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CartonAggregation extends Model
{
    use HasFactory;

    protected $fillable = [
        'carton_serial', 'po_id', 'factory_id', 'batch_id', 'unit_count',
        'carton_merkle_root', 'carton_signature', 'status', 'sealed_by_device',
    ];

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(CartonUnit::class, 'carton_id')->orderBy('leaf_index');
    }
}
