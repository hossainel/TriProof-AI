<?php

namespace App\Console\Commands;

use App\Models\SigningKey;
use App\Models\User;
use App\Services\Crypto\MasterKeyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class RotateSigningKey extends Command
{
    protected $signature = 'triproof:keys:rotate
        {version? : New key version, e.g. K02}
        {--key-version= : New key version (alternative to the positional argument)}
        {--buyer= : Optional buyer ID for a buyer-isolated key}';

    protected $description = 'Create a versioned Ed25519 signing key while retaining old keys for verification';

    public function handle(): int
    {
        $version = strtoupper(trim((string) ($this->option('key-version') ?: $this->argument('version'))));
        if (! preg_match('/^K\d{2}$/', $version)) {
            $this->error('Provide a version such as --version=K02.');

            return self::FAILURE;
        }

        if (SigningKey::query()->where('key_version', $version)->exists()) {
            $this->error("Key version {$version} already exists.");

            return self::FAILURE;
        }

        $buyerId = $this->option('buyer') !== null ? (int) $this->option('buyer') : null;
        if ($buyerId !== null && ! User::query()->whereKey($buyerId)->where('role', 'global_buyer')->exists()) {
            $this->error('The selected buyer does not exist.');

            return self::FAILURE;
        }

        $keys = MasterKeyService::generateKeypair();
        $now = now();

        DB::transaction(function () use ($version, $buyerId, $keys, $now) {
            $retire = SigningKey::query()->where('status', 'active')
                ->when($buyerId !== null, fn ($q) => $q->where('buyer_id', $buyerId), fn ($q) => $q->whereNull('buyer_id'))
                ->get();

            foreach ($retire as $old) {
                $old->update(['status' => 'verify_only', 'retired_at' => $now]);
            }

            SigningKey::create([
                'key_version' => $version,
                'buyer_id' => $buyerId,
                'public_key_b64' => $keys['public_key'],
                'private_key_encrypted' => Crypt::encryptString($keys['private_key']),
                'status' => 'active',
                'activated_at' => $now,
            ]);
        });

        $this->info("Key {$version} is active. Previous keys remain verify_only.");
        $this->line('Public key: '.$keys['public_key']);

        return self::SUCCESS;
    }
}
