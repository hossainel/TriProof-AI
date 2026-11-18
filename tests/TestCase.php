<?php

namespace Tests;

use App\Services\Crypto\MasterKeyService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $keys = MasterKeyService::generateKeypair();

        config([
            'triproof.crypto.private_key' => $keys['private_key'],
            'triproof.crypto.public_key' => $keys['public_key'],
            'triproof.crypto.key_id' => hash('sha256', $keys['public_key']),
            'triproof.ai.provider' => 'none',
            'triproof.ai.api_key' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
