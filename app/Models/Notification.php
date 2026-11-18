<?php

namespace App\Models;

use App\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'device_token',
        'outer_serial',
        'severity',
        'title_en',
        'message_en',
        'title_bn',
        'message_bn',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'severity' => NotificationSeverity::class,
            'is_read' => 'boolean',
        ];
    }
}
