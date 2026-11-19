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

    /** Verify the Flutter TRIPROOF_TRANSFER_V1 QR payload during cloud sync. */
    public function redeemFlutterPayload(SerialRegistry $unit, mixed $payload, string $recipient): void
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($payload)) {
            throw new BusinessRuleException('invalid_transfer_payload', 'Transfer payload is invalid.', 422);
        }

        $protocol = (string) ($payload['protocol'] ?? '');
        $outer = (string) ($payload['outer_serial'] ?? '');
        $unitHash = (string) ($payload['unit_hash'] ?? $unit->unit_hash);
        $from = (string) ($payload['from_device_token'] ?? '');
        $nonce = (string) ($payload['nonce'] ?? '');
        $issuedAt = (string) ($payload['issued_at'] ?? '');
        $proofTag = (string) ($payload['proof_tag'] ?? '');

        $issued = $issuedAt !== '' ? strtotime($issuedAt) : false;
        if ($protocol !== 'TRIPROOF_TRANSFER_V1' || $outer !== $unit->outer_serial
            || $unitHash !== $unit->unit_hash || $from === '' || $nonce === '' || $recipient === $from
            || $issued === false || now()->getTimestamp() - $issued > 86400 || $issued > now()->getTimestamp() + 300) {
            throw new BusinessRuleException('invalid_transfer_payload', 'Transfer payload is invalid or expired.', 422);
        }

        $claimInput = $outer.'|'.$unitHash.'|'.$from.'|'.$nonce.'|'.$issuedAt;
        $expected = base64_encode(substr(hash('sha256', $claimInput, true), 0, 16));
        $provided = base64_encode(base64_decode(rtrim(strtr($proofTag, '-_', '+/'), '='), true) ?: '');

        if (! hash_equals($expected, $provided)
            || $unit->status !== SerialStatus::ClaimedGenuine
            || ! hash_equals((string) $unit->claimed_device_token, $from)) {
            throw new BusinessRuleException('invalid_transfer_payload', 'Transfer proof or owner does not match.', 422);
        }

        $replayKey = 'transfer-used:'.hash('sha256', $proofTag);
        if (Cache::has($replayKey)) {
            throw new BusinessRuleException('transfer_already_used', 'This transfer token was already used.', 409);
        }
        Cache::put($replayKey, true, now()->addDay());
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
