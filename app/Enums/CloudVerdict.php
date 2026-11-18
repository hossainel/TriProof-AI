<?php

namespace App\Enums;

enum CloudVerdict: string
{
    case VerifiedClean = 'verified_clean';
    case OwnerRepeatOk = 'owner_repeat_ok';
    case StoreGraceOk = 'store_grace_ok';
    case CloneDetected = 'clone_detected';
    case RecalledBatch = 'recalled_batch';
    case Expired = 'expired';
    case ForgedSignature = 'forged_signature';

    public function isPositive(): bool
    {
        return in_array($this, [self::VerifiedClean, self::OwnerRepeatOk, self::StoreGraceOk], true);
    }
}
