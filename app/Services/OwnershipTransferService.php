<?php

namespace App\Services;

use App\Enums\SerialStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\SerialRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OwnershipTransferService
{
    public function issue(string $outer, string $inner, string $device): array
    {
        return DB::transaction(function () use ($outer, $inner, $device) {
            $unit = SerialRegistry::query()->where('outer_serial', $outer)->lockForUpdate()->first();
            if (! $unit || $unit->status !== SerialStatus::ClaimedGenuine || ! hash_equals((string) $unit->claimed_device_token, $device)
                || ! hash_equals($unit->inner_serial, $inner) || now()->toDateString() > $unit->exp_date->toDateString()) {
                throw new BusinessRuleException('handoff_unavailable', 'Only the current owner of a verified, unexpired unit can issue a handoff.', 403);
            }

            $ticket = bin2hex(random_bytes(32));
            $expiry = now()->addDay();
            Cache::put('handoff:'.$unit->unit_hash, hash('sha256', $device.'|'.$ticket), $expiry);

            return ['ticket' => $ticket, 'expires_at' => $expiry->toIso8601String()];
        });
    }

    /** Called only after signature, registry, recall and expiry checks, under the unit's row lock. */
    public function redeem(SerialRegistry $unit, string $ticket, string $recipient): void
    {
        $key = 'handoff:'.$unit->unit_hash;
        if ($unit->status !== SerialStatus::ClaimedGenuine || $recipient === $unit->claimed_device_token || now()->toDateString() > $unit->exp_date->toDateString()
            || ! hash_equals((string) Cache::get($key), hash('sha256', $unit->claimed_device_token.'|'.$ticket))) {
            throw new BusinessRuleException('invalid_handoff', 'The handoff ticket is invalid, expired, or already used.', 422);
        }
        Cache::forget($key);
    }
}
