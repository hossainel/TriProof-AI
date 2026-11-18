<?php

namespace App\Enums;

enum SerialStatus: string
{
    case Generated = 'generated';
    case Packed = 'packed';
    case InTransit = 'in_transit';
    case ClaimedGenuine = 'claimed_genuine';
    case ClonedFlagged = 'cloned_flagged';
    case Recalled = 'recalled';
}
