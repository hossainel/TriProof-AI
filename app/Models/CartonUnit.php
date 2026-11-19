<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartonUnit extends Model
{
    use HasFactory;

    protected $fillable = ['carton_id', 'serial_registry_id', 'leaf_index'];

    public function carton(): BelongsTo
    {
        return $this->belongsTo(CartonAggregation::class);
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(SerialRegistry::class, 'serial_registry_id');
    }
}
