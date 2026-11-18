<?php

namespace App\Enums;

enum NotificationSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case CriticalClone = 'critical_clone';
    case RecallAlert = 'recall_alert';
}
