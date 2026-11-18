<?php

namespace App\Support;

class DeviceToken
{
    public static function mask(?string $token): string
    {
        if (! $token) {
            return 'unknown-device';
        }

        if (strlen($token) <= 8) {
            return substr($token, 0, 3).'***';
        }

        return substr($token, 0, 6).'…'.substr($token, -4);
    }
}
