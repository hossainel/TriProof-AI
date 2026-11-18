<?php

namespace App\Models;

use App\Enums\SerialStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SerialRegistry extends Model
{
    use HasFactory;

    protected $table = 'serial_registry';

    protected $fillable = [
        'po_id',
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
        'claimed_device_token',
        'claimed_at',
        'claimed_gps',
        'rework_parent_serial',
    ];

    protected function casts(): array
    {
        return [
            'unit_number' => 'integer',
            'prod_date' => 'date',
            'exp_date' => 'date',
            'status' => SerialStatus::class,
            'claimed_at' => 'datetime',
        ];
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ScanLog::class, 'outer_serial', 'outer_serial');
    }

    public function scopeForBatch($query, string $factoryId, string $machineId, string $batchId)
    {
        return $query
            ->where('factory_id', $factoryId)
            ->where('machine_id', $machineId)
            ->where('batch_id', $batchId);
    }

    public function claimedCoordinates(): ?array
    {
        if (! $this->claimed_gps) {
            return null;
        }

        $parts = explode(',', $this->claimed_gps);

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        return [(float) $parts[0], (float) $parts[1]];
    }
}
