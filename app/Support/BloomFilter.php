<?php

namespace App\Support;

class BloomFilter
{
    public const SEED = 0x54524950; // "TRIP"

    public static function parameters(int $expected, float $falsePositiveRate = 0.001, ?int $maxBits = null): array
    {
        $expected = max(1, $expected);
        $rate = min(0.1, max(0.000001, $falsePositiveRate));
        $m = (int) ceil(-($expected * log($rate)) / (log(2) ** 2));
        if ($maxBits !== null && $maxBits > 0) {
            $m = min($m, $maxBits);
        }
        $m = (int) (ceil($m / 8) * 8);
        $k = max(1, (int) round(($m / $expected) * log(2)));

        return ['m_bits' => $m, 'k_hashes' => $k, 'seed' => self::SEED];
    }

    public static function build(array $hashes, ?int $expected = null, float $falsePositiveRate = 0.001, ?int $maxBits = null): array
    {
        $hashes = array_values(array_filter($hashes, 'is_string'));
        $expected ??= max(30_000, count($hashes));
        $params = self::parameters($expected, $falsePositiveRate, $maxBits);
        $m = $params['m_bits'];
        $k = $params['k_hashes'];
        $seed = $params['seed'];
        $bytes = str_repeat("\0", (int) ($m / 8));

        foreach (array_unique($hashes) as $hash) {
            foreach (self::positions($hash, $m, $k, $seed) as $position) {
                $byte = intdiv($position, 8);
                $bytes[$byte] = chr(ord($bytes[$byte]) | (1 << ($position % 8)));
            }
        }

        return array_merge($params, [
            'bloom_bits_b64' => base64_encode($bytes),
            'entries' => count(array_unique($hashes)),
            'target_entries' => $expected,
            'estimated_fp_rate' => round((1 - exp(-$k * count(array_unique($hashes)) / $m)) ** $k, 8),
        ]);
    }

    public static function check(string $hash, string $bitsBase64, int $mBits, int $kHashes, int $seed = self::SEED): bool
    {
        $bytes = base64_decode($bitsBase64, true);
        if ($bytes === false || $mBits < 8 || $kHashes < 1) {
            return false;
        }

        foreach (self::positions($hash, $mBits, $kHashes, $seed) as $position) {
            $byte = intdiv($position, 8);
            if (! isset($bytes[$byte]) || (ord($bytes[$byte]) & (1 << ($position % 8))) === 0) {
                return false;
            }
        }

        return true;
    }

    private static function positions(string $value, int $m, int $k, int $seed): array
    {
        $key = pack('N', $seed);
        $positions = [];

        for ($i = 0; $i < $k; $i++) {
            $digest = hash_hmac('sha256', $value.':'.$i, $key, true);
            $word = unpack('N', substr($digest, 0, 4))[1] & 0x7FFFFFFF;
            $positions[] = $word % $m;
        }

        return $positions;
    }
}
