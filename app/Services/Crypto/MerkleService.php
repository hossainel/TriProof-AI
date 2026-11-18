<?php

namespace App\Services\Crypto;

use App\Models\PurchaseOrder;
use InvalidArgumentException;

class MerkleService
{
    /**
     * Build a deterministic SHA-256 Merkle root over hexadecimal unit hashes.
     * Leaves keep insertion order; odd nodes are promoted; parents hash with
     * a 0x01 domain-separation byte to avoid second-preimage ambiguity.
     */
    public function root(array $hexHashes): ?string
    {
        $level = $this->leafLevel($hexHashes);

        if ($level === []) {
            return null;
        }

        while (count($level) > 1) {
            $next = [];
            $count = count($level);

            for ($i = 0; $i < $count; $i += 2) {
                if ($i + 1 === $count) {
                    $next[] = $level[$i];
                } else {
                    $next[] = hash('sha256', "\x01".$level[$i].$level[$i + 1], true);
                }
            }

            $level = $next;
        }

        return bin2hex($level[0]);
    }

    public function verify(array $hexHashes, string $expectedRoot): bool
    {
        $computed = $this->root($hexHashes);

        return $computed !== null && hash_equals(strtolower($expectedRoot), $computed);
    }

    public function rootForOrder(PurchaseOrder $order): ?string
    {
        $hashes = $order->serials()->orderBy('id')->pluck('unit_hash')->all();

        return $this->root($hashes);
    }

    public function levels(array $hexHashes): array
    {
        $level = $this->leafLevel($hexHashes);
        $levels = [array_map('bin2hex', $level)];

        while (count($level) > 1) {
            $next = [];
            $count = count($level);

            for ($i = 0; $i < $count; $i += 2) {
                if ($i + 1 === $count) {
                    $next[] = $level[$i];
                } else {
                    $next[] = hash('sha256', "\x01".$level[$i].$level[$i + 1], true);
                }
            }

            $level = $next;
            $levels[] = array_map('bin2hex', $level);
        }

        return $levels;
    }

    public function proof(array $hexHashes, int $index): array
    {
        $levels = $this->levels($hexHashes);

        if (! isset($levels[0][$index])) {
            throw new InvalidArgumentException('Leaf index is out of range.');
        }

        $path = [];
        $idx = $index;

        for ($levelIndex = 0; $levelIndex < count($levels) - 1; $levelIndex++) {
            $level = $levels[$levelIndex];
            $sibling = $idx % 2 === 0 ? $idx + 1 : $idx - 1;

            if (isset($level[$sibling])) {
                $path[] = ['hash' => $level[$sibling], 'position' => $sibling > $idx ? 'right' : 'left'];
            }

            $idx = intdiv($idx, 2);
        }

        return $path;
    }

    public function verifyProof(string $leafHash, array $proof, string $root): bool
    {
        $cursor = hex2bin($leafHash);

        if ($cursor === false) {
            return false;
        }

        foreach ($proof as $step) {
            $sibling = hex2bin((string) ($step['hash'] ?? ''));

            if ($sibling === false) {
                return false;
            }

            $cursor = ($step['position'] ?? '') === 'left'
                ? hash('sha256', "\x01".$sibling.$cursor, true)
                : hash('sha256', "\x01".$cursor.$sibling, true);
        }

        return hash_equals(strtolower($root), bin2hex($cursor));
    }

    private function leafLevel(array $hexHashes): array
    {
        $leaves = [];

        foreach ($hexHashes as $hash) {
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                throw new InvalidArgumentException('Merkle leaves must be 64-character hexadecimal SHA-256 hashes.');
            }

            $leaves[] = hex2bin(strtolower($hash));
        }

        return $leaves;
    }
}
