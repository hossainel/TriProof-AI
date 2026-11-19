<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceAttestation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceAttestationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_token' => ['required', 'string', 'max:128'],
            'device_public_key_b64' => ['required', 'string', 'max:88'],
            'play_integrity_verdict' => ['nullable', 'string', 'max:64'],
        ]);

        $raw = base64_decode($data['device_public_key_b64'], true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ApiResponse::error('invalid_device_key', 'Device key must be a raw 32-byte Base64 Ed25519 public key.', 422);
        }

        $attestation = DeviceAttestation::query()->updateOrCreate(
            ['device_token' => $data['device_token']],
            [
                'device_public_key_b64' => $data['device_public_key_b64'],
                'play_integrity_verdict' => $data['play_integrity_verdict'] ?? null,
            ]
        );

        return ApiResponse::success([
            'device_token' => $attestation->device_token,
            'last_scan_seq' => (int) $attestation->last_scan_seq,
        ], 'Device attestation registered.');
    }
}
