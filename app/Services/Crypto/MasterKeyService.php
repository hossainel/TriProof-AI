<?php

namespace App\Services\Crypto;

use App\Models\SigningKey;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class MasterKeyService
{
    public const LEGACY_VERSION = 'K00';

    public function privateKey(?string $version = null): string
    {
        $version = $this->normalizeVersion($version);

        if ($version === self::LEGACY_VERSION) {
            return $this->decodeKey(config('triproof.crypto.private_key'), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'HQ_PRIVATE_SIGNING_KEY');
        }

        $key = $this->databaseKey($version);
        $decrypted = Crypt::decryptString($key->private_key_encrypted);

        return $this->decodeKey($decrypted, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, "signing key {$version}");
    }

    public function publicKey(?string $version = null): string
    {
        $version = $this->normalizeVersion($version);

        if ($version === self::LEGACY_VERSION) {
            return $this->decodeKey(config('triproof.crypto.public_key'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'UNIVERSAL_PUBLIC_VERIFICATION_KEY');
        }

        return $this->decodeKey($this->databaseKey($version)->public_key_b64, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, "signing key {$version}");
    }

    public function publicKeyBase64(?string $version = null): string
    {
        return base64_encode($this->publicKey($version));
    }

    public function isConfigured(?string $version = null): bool
    {
        try {
            $this->assertPairMatches($version);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function keyId(?string $version = null): string
    {
        return substr(hash('sha256', $this->publicKey($version)), 0, 32);
    }

    public function assertPairMatches(?string $version = null): void
    {
        $derived = sodium_crypto_sign_publickey_from_secretkey($this->privateKey($version));

        if (! hash_equals($derived, $this->publicKey($version))) {
            throw new RuntimeException('Private and public key mismatch for '.($version ?? self::LEGACY_VERSION).'.');
        }
    }

    public function activeVersion(?int $buyerId = null): string
    {
        $query = SigningKey::query()->where('status', 'active');

        if ($buyerId !== null) {
            $query->where(function ($q) use ($buyerId) {
                $q->where('buyer_id', $buyerId)->orWhereNull('buyer_id');
            })->orderByDesc('buyer_id');
        } else {
            $query->whereNull('buyer_id');
        }

        return (string) ($query->orderByDesc('activated_at')->value('key_version') ?: self::LEGACY_VERSION);
    }

    /** Full, trusted key ring for mobile and transparency endpoints. Private keys never appear. */
    public function keyRing(): array
    {
        $keys = [];

        $legacy = (string) config('triproof.crypto.public_key');
        $decoded = base64_decode($legacy, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $fingerprint = hash('sha256', $decoded);
            $keys[] = [
                'key_version' => self::LEGACY_VERSION,
                'status' => 'verify_only',
                'buyer_id' => null,
                'public_key_base64' => $legacy,
                'key_fingerprint' => $fingerprint,
                'key_id' => substr($fingerprint, 0, 32),
                'algorithm' => 'Ed25519',
            ];
        }

        foreach (SigningKey::query()->usable()->orderBy('key_version')->get() as $key) {
            $raw = base64_decode($key->public_key_b64, true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }
            $fingerprint = hash('sha256', $raw);
            $keys[] = [
                'key_version' => $key->key_version,
                'status' => $key->status,
                'buyer_id' => $key->buyer_id,
                'public_key_base64' => $key->public_key_b64,
                'key_fingerprint' => $fingerprint,
                'key_id' => substr($fingerprint, 0, 32),
                'algorithm' => 'Ed25519',
                'activated_at' => $key->activated_at?->toIso8601String(),
                'retired_at' => $key->retired_at?->toIso8601String(),
            ];
        }

        return $keys;
    }

    public function normalizeVersion(?string $version): string
    {
        $version = strtoupper(trim((string) ($version ?? self::LEGACY_VERSION)));

        if ($version === '') {
            return self::LEGACY_VERSION;
        }

        if (! preg_match('/^K\d{2}$/', $version)) {
            throw new RuntimeException('Invalid key version.');
        }

        return $version;
    }

    public static function generateKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ];
    }

    private function databaseKey(string $version): SigningKey
    {
        $key = SigningKey::query()->where('key_version', $version)->whereIn('status', ['active', 'verify_only'])->first();

        if (! $key) {
            throw new RuntimeException("Signing key {$version} is not available.");
        }

        return $key;
    }

    private function decodeKey(mixed $value, int $expectedBytes, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$name} is not configured.");
        }

        $decoded = base64_decode(trim($value), true);

        if ($decoded === false || strlen($decoded) !== $expectedBytes) {
            throw new RuntimeException("{$name} must be valid Base64 encoding exactly {$expectedBytes} bytes.");
        }

        return $decoded;
    }
}
