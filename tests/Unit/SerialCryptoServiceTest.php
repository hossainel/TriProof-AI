<?php

namespace Tests\Unit;

use App\Services\Crypto\MasterKeyService;
use App\Services\Crypto\SerialCryptoService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SerialCryptoServiceTest extends TestCase
{
    private SerialCryptoService $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crypto = app(SerialCryptoService::class);
    }

    public function test_it_signs_and_verifies_the_outer_serial(): void
    {
        $outer = $this->crypto->formatOuterSerial(
            'DHK04',
            'M01',
            'B01',
            42,
            CarbonImmutable::parse('2026-11-18'),
            CarbonImmutable::parse('2027-11-18'),
        );

        $inner = $this->crypto->sign($outer);

        $this->assertSame('DHK04-M01-B01-000042|20261118|20271118', $outer);
        $this->assertTrue($this->crypto->verify($outer, $inner));
        $this->assertSame($this->crypto->unitHash($outer, $inner), hash('sha256', $outer.'|'.$inner));
    }

    public function test_tampered_or_malformed_signatures_are_rejected(): void
    {
        $outer = 'DHK04-M01-B01-000001|20261118|20271118';
        $inner = $this->crypto->sign($outer);
        $tamperedOuter = 'DHK04-M01-B01-000002|20261118|20271118';

        $this->assertFalse($this->crypto->verify($tamperedOuter, $inner));
        $this->assertFalse($this->crypto->verify($outer, 'not-base64'));
        $this->assertFalse($this->crypto->verify($outer, base64_encode('short')));
        $this->assertFalse($this->crypto->verify($outer, substr($inner, 0, -4)));
    }

    public function test_outer_serial_parser_rejects_non_contract_values(): void
    {
        $this->assertSame([
            'factory_id' => 'DHK04',
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'unit_number' => 7,
            'prod_date' => '20261118',
            'exp_date' => '20271118',
        ], $this->crypto->parseOuterSerial('DHK04-M01-B01-000007|20261118|20271118'));

        $this->assertNull($this->crypto->parseOuterSerial('DHK04-M01-B01-7|20261118|20271118'));
        $this->assertNull($this->crypto->parseOuterSerial('lower-M01-B01-000007|20261118|20271118'));
        $this->assertFalse($this->crypto->isValidOuterSerial('DHK04-M01-B01-000007|20261118'));
    }

    public function test_generation_rejects_invalid_identifiers_and_unit_ranges(): void
    {
        $date = CarbonImmutable::parse('2026-11-18');
        $expiry = $date->addYear();

        $this->expectException(\InvalidArgumentException::class);
        $this->crypto->formatOuterSerial('DHK04', 'M 01', 'B01', 1, $date, $expiry);
    }

    public function test_a_mismatched_key_pair_is_reported_as_unconfigured(): void
    {
        $other = MasterKeyService::generateKeypair();
        config(['triproof.crypto.public_key' => $other['public_key']]);

        $this->assertFalse(app(MasterKeyService::class)->isConfigured());
    }
}
