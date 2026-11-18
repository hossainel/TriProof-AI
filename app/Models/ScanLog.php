<?php

namespace App\Models;

use App\Enums\CloudVerdict;
use App\Enums\ScanMode;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScanLog extends Model
{
    protected $fillable = [
        'sync_uuid',
        'outer_serial',
        'inner_serial',
        'device_token',
        'user_role',
        'scan_mode',
        'offline_verdict',
        'cloud_cross_check_verdict',
        'gps_lat',
        'gps_lng',
        'scanned_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'user_role' => UserRole::class,
            'scan_mode' => ScanMode::class,
            'cloud_cross_check_verdict' => CloudVerdict::class,
            'gps_lat' => 'float',
            'gps_lng' => 'float',
            'scanned_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function registry(): HasOne
    {
        return $this->hasOne(SerialRegistry::class, 'outer_serial', 'outer_serial');
    }
}
