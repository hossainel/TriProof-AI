<?php

namespace App\Enums;

enum ScanMode: string
{
    case OfflineSynced = 'offline_synced';
    case OnlineLive = 'online_live';
}
