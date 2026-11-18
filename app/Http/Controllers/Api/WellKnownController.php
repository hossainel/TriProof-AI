<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class WellKnownController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $publicBase64 = (string) config('triproof.crypto.public_key');
        $public = base64_decode($publicBase64, true);

        if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ApiResponse::error('crypto_unconfigured', 'The public verification key is not configured.', 503);
        }

        return ApiResponse::success([
            'issuer' => 'TriProof AI',
            'algorithm' => 'Ed25519',
            'public_key_base64' => $publicBase64,
            'key_fingerprint' => hash('sha256', $public),
            'key_id' => substr(hash('sha256', $public), 0, 32),
            'serial_format' => '{FactoryID}-{MachineID}-{BatchID}-{UnitNumber:06d}|{ProdYYYYMMDD}|{ExpYYYYMMDD}',
            'inner_serial_format' => 'Base64(Ed25519 detached signature of outer serial)',
            'unit_hash' => 'SHA-256(outer_serial + "|" + inner_serial)',
            'offline_trust_model' => 'Verify the Ed25519 signature locally; cloud cross-check establishes ownership and recall state.',
        ]);
    }
}
