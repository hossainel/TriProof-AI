<?php

namespace App\Services\Crypto;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

class SerialCryptoService
{
    private const FACTORY_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,15}$/';

    private const MACHINE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,15}$/';

    private const BATCH_PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,15}$/';

    // Canonical labels use ISO YYYY-MM-DD; legacy YYYYMMDD remains parseable.
    private const DATE_PATTERN = '(\d{4}-\d{2}-\d{2}|\d{8})';

    private const LEGACY_PATTERN = '/^([A-Z0-9][A-Z0-9-]{1,15})-([A-Z0-9][A-Z0-9-]{0,15})-([A-Z0-9][A-Z0-9-]{0,15})-(\d{6})\|'.self::DATE_PATTERN.'\|'.self::DATE_PATTERN.'$/';

    private const VERSIONED_PATTERN = '/^(K\d{2})-([A-Z0-9][A-Z0-9-]{1,15})-([A-Z0-9][A-Z0-9-]{0,15})-([A-Z0-9][A-Z0-9-]{0,15})-(\d{6})\|'.self::DATE_PATTERN.'\|'.self::DATE_PATTERN.'\|DPP:([A-F0-9]{8})$/';

    public function __construct(private readonly MasterKeyService $keys) {}

    public function formatOuterSerial(
        string $factoryId,
        string $machineId,
        string $batchId,
        int $unitNumber,
        CarbonInterface|DateTimeInterface $prodDate,
        CarbonInterface|DateTimeInterface $expDate,
        ?string $keyVersion = null,
        ?string $dppDigest = null,
    ): string {
        $factoryId = strtoupper(trim($factoryId));
        $machineId = strtoupper(trim($machineId));
        $batchId = strtoupper(trim($batchId));

        if (! preg_match(self::FACTORY_PATTERN, $factoryId)) {
            throw new InvalidArgumentException('Factory ID must be 2-16 uppercase alphanumeric/hyphen characters.');
        }
        if (! preg_match(self::MACHINE_PATTERN, $machineId)) {
            throw new InvalidArgumentException('Machine ID must be 1-16 uppercase alphanumeric/hyphen characters.');
        }
        if (! preg_match(self::BATCH_PATTERN, $batchId)) {
            throw new InvalidArgumentException('Batch ID must be 1-16 uppercase alphanumeric/hyphen characters.');
        }
        if ($unitNumber < 1 || $unitNumber > 999999) {
            throw new InvalidArgumentException('Unit number must be between 1 and 999999.');
        }

        $keyVersion = $dppDigest !== null || $keyVersion !== null
            ? $this->keys->normalizeVersion($keyVersion)
            : null;

        if ($dppDigest !== null) {
            $dppDigest = strtoupper(trim($dppDigest));
            if (! preg_match('/^[A-F0-9]{8}$/', $dppDigest)) {
                throw new InvalidArgumentException('DPP digest must be 8 hexadecimal characters.');
            }
        }

        $body = sprintf('%s-%s-%s-%06d|%s|%s', $factoryId, $machineId, $batchId, $unitNumber,
            $prodDate->format('Y-m-d'), $expDate->format('Y-m-d'));

        if ($keyVersion === null && $dppDigest === null) {
            return $body; // v1 legacy contract, unchanged.
        }

        return ($keyVersion ?? $this->keys->activeVersion()).'-'.$body.($dppDigest !== null ? '|DPP:'.$dppDigest : '');
    }

    public function sign(string $outerSerial): string
    {
        return $this->signForVersion($outerSerial, $this->keyVersionOf($outerSerial));
    }

    public function signForVersion(string $message, string $version): string
    {
        $version = $this->keys->normalizeVersion($version);
        $this->keys->assertPairMatches($version);

        return base64_encode(sodium_crypto_sign_detached($message, $this->keys->privateKey($version)));
    }

    public function verify(string $outerSerial, string $innerSerial): bool
    {
        return $this->verifyForVersion($outerSerial, $innerSerial, $this->keyVersionOf($outerSerial));
    }

    public function verifyForVersion(string $message, string $signature, string $version): bool
    {
        $decoded = base64_decode(trim($signature), true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($decoded, $message, $this->keys->publicKey($version));
        } catch (\Throwable) {
            return false;
        }
    }

    public function unitHash(string $outerSerial, string $innerSerial): string
    {
        return hash('sha256', $outerSerial.'|'.$innerSerial);
    }

    public function isValidOuterSerial(string $outerSerial): bool
    {
        return (bool) preg_match(self::LEGACY_PATTERN, $outerSerial)
            || (bool) preg_match(self::VERSIONED_PATTERN, $outerSerial);
    }

    public function parseOuterSerial(string $outerSerial): ?array
    {
        $outerSerial = $this->normalizePayload($outerSerial);

        if (preg_match(self::LEGACY_PATTERN, $outerSerial, $m)) {
            $prod = $this->normalizeDate($m[5]);
            $exp = $this->normalizeDate($m[6]);
            if ($prod === null || $exp === null || $exp < $prod) {
                return null;
            }

            return [
                'factory_id' => $m[1], 'machine_id' => $m[2], 'batch_id' => $m[3],
                'unit_number' => (int) $m[4], 'prod_date' => $prod, 'exp_date' => $exp,
            ];
        }

        if (preg_match(self::VERSIONED_PATTERN, $outerSerial, $m)) {
            $prod = $this->normalizeDate($m[6]);
            $exp = $this->normalizeDate($m[7]);
            if ($prod === null || $exp === null || $exp < $prod) {
                return null;
            }

            return [
                'key_version' => $m[1],
                'factory_id' => $m[2], 'machine_id' => $m[3], 'batch_id' => $m[4],
                'unit_number' => (int) $m[5], 'prod_date' => $prod, 'exp_date' => $exp,
                'dpp_digest' => $m[8],
            ];
        }

        return null;
    }

    private function normalizeDate(string $raw): ?string
    {
        if (preg_match('/^\d{8}$/', $raw)) {
            $raw = substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $raw);

        return $parsed && $parsed->format('Y-m-d') === $raw ? $raw : null;
    }

    public function keyVersionOf(string $serial): string
    {
        if (preg_match('/^(K\d{2})-/', strtoupper(trim($serial)), $m)) {
            return $m[1];
        }

        return MasterKeyService::LEGACY_VERSION;
    }

    /** Accept raw legacy/versioned serials and Hybrid Smart URL payloads. */
    public function normalizePayload(string $payload): string
    {
        $payload = trim($payload);
        if (preg_match('~^[a-z]+://~i', $payload)) {
            $parts = parse_url($payload);
            $path = (string) ($parts['path'] ?? '');
            if (preg_match('~/v/(.+)$~', $path, $m)) {
                $payload = rawurldecode($m[1]);
            }
        }

        return $payload;
    }

    public function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }
}
