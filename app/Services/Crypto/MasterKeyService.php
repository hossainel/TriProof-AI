<?php

namespace App\Services\Crypto;

use RuntimeException;

class MasterKeyService
{
    public function privateKey(): string
    {
        return $this->decodeKey(config('triproof.crypto.private_key'), SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'HQ_PRIVATE_SIGNING_KEY');
    }

    public function publicKey(): string
    {
        return $this->decodeKey(config('triproof.crypto.public_key'), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'UNIVERSAL_PUBLIC_VERIFICATION_KEY');
    }

    public function isConfigured(): bool
    {
        try {
            $this->assertPairMatches();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey());
    }

    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 32);
    }

    public function assertPairMatches(): void
    {
        $derived = sodium_crypto_sign_publickey_from_secretkey($this->privateKey());

        if (! hash_equals($derived, $this->publicKey())) {
            throw new RuntimeException('HQ private signing key does not match the universal public verification key.');
        }
    }

    public static function generateKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ];
    }

    private function decodeKey(mixed $value, int $expectedBytes, string $name): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$name} is not configured. Run: php artisan triproof:keys --write");
        }

        $decoded = base64_decode(trim($value), true);

        if ($decoded === false || strlen($decoded) !== $expectedBytes) {
            throw new RuntimeException("{$name} must be valid Base64 encoding exactly {$expectedBytes} bytes.");
        }

        return $decoded;
    }
}
