<?php

namespace App\Console\Commands;

use App\Services\Crypto\MasterKeyService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateSigningKeys extends Command
{
    protected $signature = 'triproof:keys
        {--write : Write the generated keys into .env}
        {--force : Replace existing keys}';

    protected $description = 'Generate the TriProof Ed25519 HQ signing keypair';

    public function handle(): int
    {
        $existingPrivate = (string) config('triproof.crypto.private_key');
        $existingPublic = (string) config('triproof.crypto.public_key');

        if (! $this->option('force') && ($existingPrivate !== '' || $existingPublic !== '')) {
            $this->error('Signing keys already exist. Use --force to rotate them (this invalidates old offline labels).');

            return self::FAILURE;
        }

        $keys = MasterKeyService::generateKeypair();

        $this->info('TriProof Ed25519 keypair generated.');
        $this->line('Public verification key (safe to embed):');
        $this->line($keys['public_key']);
        $this->newLine();

        if ($this->option('write')) {
            $path = base_path('.env');
            if (! is_file($path)) {
                $this->error('.env not found. Copy .env.example to .env first.');

                return self::FAILURE;
            }

            $contents = (string) file_get_contents($path);
            $contents = $this->setEnvValue($contents, 'HQ_PRIVATE_SIGNING_KEY', $keys['private_key']);
            $contents = $this->setEnvValue($contents, 'UNIVERSAL_PUBLIC_VERIFICATION_KEY', $keys['public_key']);

            if (! preg_match('/^JWT_SECRET=./m', $contents)) {
                $contents = $this->setEnvValue($contents, 'JWT_SECRET', Str::random(64));
            }

            file_put_contents($path, $contents);
            @chmod($path, 0600);

            $this->info('Keys written to .env. The private key was not printed.');
            $this->call('config:clear');
        } else {
            $this->warn('Private signing key (store only in .env; never commit or share):');
            $this->line($keys['private_key']);
            $this->newLine();
            $this->line('Add both keys to .env, or re-run with --write.');
        }

        return self::SUCCESS;
    }

    private function setEnvValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents)) {
            return (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents, 1);
        }

        return rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
    }
}
