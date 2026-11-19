<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceAttestation extends Model
{
    use HasFactory;

    protected $table = 'device_attestations';

    protected $primaryKey = 'device_token';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'device_token', 'device_public_key_b64', 'play_integrity_verdict', 'last_scan_seq',
    ];
}
