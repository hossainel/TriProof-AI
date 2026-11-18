<?php

namespace App\Services\Crypto;

use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

class SerialCryptoService
{
    private const FACTORY_PATTERN = '/^[A-Z0-9][A-Z0-9-]{1,15}$/';

    private const MACHINE_PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,15}$/';

    private const BATCH_PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,15}$/';

    private const OUTER_PATTERN = '/^([A-Z0-9][A-Z0-9-]{1,15})-([A-Z0-9][A-Z0-9-]{0,15})-([A-Z0-9][A-Z0-9-]{0,15})-(\d{6})\|(\d{8})\|(\d{8})$/';

    public function __construct(private readonly MasterKeyService $keys) {}

    public function formatOuterSerial(
        string $factoryId,
        string $machineId,
        string $batchId,
        int $unitNumber,
        CarbonInterface|DateTimeInterface $prodDate,
        CarbonInterface|DateTimeInterface $expDate,
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

        return sprintf(
            '%s-%s-%s-%06d|%s|%s',
            $factoryId,
            $machineId,
            $batchId,
            $unitNumber,
            $prodDate->format('Ymd'),
            $expDate->format('Ymd'),
        );
    }

    public function sign(string $outerSerial): string
    {
        $this->keys->assertPairMatches();

        return base64_encode(sodium_crypto_sign_detached($outerSerial, $this->keys->privateKey()));
    }

    public function verify(string $outerSerial, string $innerSerial): bool
    {
        $decoded = base64_decode(trim($innerSerial), true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($decoded, $outerSerial, $this->keys->publicKey());
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
        return (bool) preg_match(self::OUTER_PATTERN, $outerSerial);
    }

    public function parseOuterSerial(string $outerSerial): ?array
    {
        if (! preg_match(self::OUTER_PATTERN, $outerSerial, $m)) {
            return null;
        }

        return [
            'factory_id' => $m[1],
            'machine_id' => $m[2],
            'batch_id' => $m[3],
            'unit_number' => (int) $m[4],
            'prod_date' => $m[5],
            'exp_date' => $m[6],
        ];
    }

    public function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }
}
