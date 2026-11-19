<?php

namespace Tests\Feature;

use App\Models\BlockchainAnchor;
use App\Models\DeviceAttestation;
use App\Services\Auth\JwtService;
use App\Services\CartonAggregationService;
use App\Services\Crypto\SerialCryptoService;
use App\Services\DppService;
use App\Support\BloomFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class V2EnterpriseTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    public function test_key_rotation_and_dpp_digest_produce_verifiable_v2_serials(): void
    {
        [$order, $buyer] = $this->makeOrder(6);
        $metadata = [
            'yarn_mill' => 'Yarn Mill Ltd',
            'dye_house' => 'Eco Dye House',
            'fabric_composition' => '100% Organic Cotton',
            'oeko_tex_id' => 'OEKO-123',
            'gots_id' => 'GOTS-456',
            'carbon_footprint_kg' => 4.2,
            'water_footprint_liters' => 80,
        ];
        $digest = app(DppService::class)->digest($metadata);
        $order->update(['dpp_metadata' => $metadata, 'dpp_digest' => $digest]);

        $this->artisan('triproof:keys:rotate', ['--key-version' => 'K01', '--buyer' => $buyer->id])
            ->assertSuccessful();

        $this->generateSerials($order, $buyer, 3);
        $serial = $order->serials()->orderBy('unit_number')->first();

        $this->assertStringStartsWith('K01-'.$order->factory_id.'-', $serial->outer_serial);
        $this->assertStringEndsWith('|DPP:'.$digest, $serial->outer_serial);
        $this->assertTrue(app(SerialCryptoService::class)->verify($serial->outer_serial, $serial->inner_serial));

        $wellKnown = $this->getJson('/.well-known/triproof-key.json')->assertOk()->json('data');
        $this->assertSame('K01', $wellKnown['active_keys'][0]['key_version']);
        $this->assertNotEmpty($wellKnown['verify_only_keys']);
        $this->assertArrayHasKey('key_ring', $wellKnown);
    }

    public function test_cartons_aggregate_and_return_merkle_inclusion_proofs(): void
    {
        [$order, $buyer] = $this->makeOrder(6);
        $this->generateSerials($order, $buyer, 3);
        $serials = $order->serials()->orderBy('unit_number')->get();

        $result = app(CartonAggregationService::class)->aggregate($buyer, [
            'po_id' => $order->id,
            'factory_id' => $order->factory_id,
            'batch_id' => $serials->first()->batch_id,
            'device_token' => 'device-carton',
            'outer_serial' => $serials->pluck('outer_serial')->all(),
        ]);

        $this->assertStringStartsWith('CTN-K00-', $result['carton_serial']);
        $this->assertSame(3, $result['unit_count']);
        $this->assertStringContainsString('|', $result['qr_payload']);

        $verified = app(CartonAggregationService::class)->verify($result['carton_serial']);
        $this->assertTrue($verified['signature_valid']);
        $this->assertTrue($verified['merkle_consistent']);
        $this->assertCount(3, $verified['manifest']);
        $this->assertNotEmpty($verified['manifest'][0]['merkle_proof']);
    }

    public function test_bloom_filter_fits_the_wire_budget_for_thirty_thousand_entries(): void
    {
        $hashes = [];
        for ($i = 0; $i < 30_000; $i++) {
            $hashes[] = hash('sha256', 'unit-'.$i);
        }

        $bloom = BloomFilter::build($hashes, 30_000, 0.001, 240_000);
        $package = base64_encode(gzencode(json_encode([
            'format' => 'triproof-offline-blacklist/v1',
            'generated_at' => now()->toIso8601String(),
            'bloom' => $bloom,
            'recalled_batches' => [],
            'cloned_serials' => [],
            'truncated' => false,
        ], JSON_THROW_ON_ERROR), 9));

        $this->assertLessThan(50_000, strlen($package));
        $this->assertSame(30_000, $bloom['entries']);
        $this->assertTrue(BloomFilter::check($hashes[0], $bloom['bloom_bits_b64'], $bloom['m_bits'], $bloom['k_hashes'], $bloom['seed']));
        $this->assertFalse(BloomFilter::check(hash('sha256', 'not-in-filter'), $bloom['bloom_bits_b64'], $bloom['m_bits'], $bloom['k_hashes'], $bloom['seed']));
    }

    public function test_public_smart_url_page_renders_product_and_dpp_data(): void
    {
        [$order, $buyer] = $this->makeOrder(4);
        $metadata = ['yarn_mill' => 'Public Mill', 'fabric_composition' => 'Linen'];
        $order->update(['dpp_metadata' => $metadata, 'dpp_digest' => app(DppService::class)->digest($metadata)]);
        $this->generateSerials($order, $buyer, 1);
        $serial = $order->serials()->first();

        $this->get(route('public.passport', ['payload' => $serial->outer_serial]))
            ->assertOk()
            ->assertSee('Product Passport')
            ->assertSee($order->factory_id)
            ->assertSee('EU Digital Product Passport')
            ->assertSee('Public Mill');
    }

    public function test_merkle_anchor_command_creates_a_local_receipt(): void
    {
        [$order, $buyer] = $this->makeOrder(3);
        $this->generateSerials($order, $buyer, 2);

        $this->artisan('triproof:anchor-merkle')->assertSuccessful();
        $anchor = BlockchainAnchor::query()->firstOrFail();

        $this->assertSame('local_ledger', $anchor->provider);
        $this->assertSame('verified', $anchor->status);
        $this->assertStringStartsWith('local:', $anchor->tx_hash_or_receipt);
    }

    public function test_monotonic_scan_sequence_rejects_replay_for_attested_devices(): void
    {
        [$order, $buyer] = $this->makeOrder(4);
        $this->generateSerials($order, $buyer, 1);
        $serial = $order->serials()->first();
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret = sodium_crypto_sign_secretkey($keypair);
        $token = app(JwtService::class)->issue($buyer)['token'];

        $this->withToken($token)->postJson('/api/v1/devices/attest', [
            'device_token' => 'attested-device',
            'device_public_key_b64' => $public,
            'play_integrity_verdict' => 'MEETS_STRONG_INTEGRITY',
        ])->assertOk();

        $firstUuid = (string) Str::uuid();
        $message = "{$firstUuid}|1|{$serial->outer_serial}";
        $this->withToken($token)->postJson('/api/v1/verify/online-crosscheck', [
            'logs' => [[
                'sync_uuid' => $firstUuid,
                'outer_serial' => $serial->outer_serial,
                'inner_serial' => $serial->inner_serial,
                'device_token' => 'attested-device',
                'scan_mode' => 'online_live',
                'scan_seq' => 1,
                'device_signature' => base64_encode(sodium_crypto_sign_detached($message, $secret)),
            ]],
        ])->assertOk();

        $this->assertSame(1, (int) DeviceAttestation::query()->where('device_token', 'attested-device')->value('last_scan_seq'));

        $replayUuid = (string) Str::uuid();
        $replayMessage = "{$replayUuid}|1|{$serial->outer_serial}";
        $this->withToken($token)->postJson('/api/v1/verify/online-crosscheck', [
            'logs' => [[
                'sync_uuid' => $replayUuid,
                'outer_serial' => $serial->outer_serial,
                'inner_serial' => $serial->inner_serial,
                'device_token' => 'attested-device',
                'scan_mode' => 'online_live',
                'scan_seq' => 1,
                'device_signature' => base64_encode(sodium_crypto_sign_detached($replayMessage, $secret)),
            ]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'scan_seq_replay');
    }

    public function test_flutter_records_contract_and_public_notifications_work(): void
    {
        [$order, $buyer] = $this->makeOrder(4);
        $this->generateSerials($order, $buyer, 1);
        $serial = $order->serials()->first();

        $this->postJson('/api/v1/verify/online-crosscheck', [
            'device_token' => 'TPD-consumer-test',
            'records' => [[
                'sync_uuid' => (string) Str::uuid(),
                'outer_serial' => $serial->outer_serial,
                'inner_serial' => $serial->inner_serial,
                'scan_mode' => 'consumer_unboxing',
            ]],
        ])->assertOk()
            ->assertJsonPath('results.0.verdict', 'verified_clean')
            ->assertJsonPath('data.results.0.verdict', 'verified_clean')
            ->assertJsonStructure(['server_time']);

        $this->getJson('/api/v1/notifications/TPD-consumer-test')
            ->assertOk()
            ->assertJsonStructure(['server_time', 'notifications', 'data'])
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_flutter_transfer_payload_transfers_ownership_on_sync(): void
    {
        [$order, $buyer] = $this->makeOrder(4);
        $this->generateSerials($order, $buyer, 1);
        $serial = $order->serials()->first();

        $this->postJson('/api/v1/verify/online-crosscheck', [
            'device_token' => 'giver-device',
            'records' => [[
                'sync_uuid' => (string) Str::uuid(),
                'outer_serial' => $serial->outer_serial,
                'inner_serial' => $serial->inner_serial,
            ]],
        ])->assertOk();

        $nonce = bin2hex(random_bytes(12));
        $issuedAt = now()->toIso8601String();
        $claim = $serial->outer_serial.'|'.$serial->unit_hash.'|giver-device|'.$nonce.'|'.$issuedAt;
        $proofTag = rtrim(strtr(base64_encode(substr(hash('sha256', $claim, true), 0, 16)), '+/', '-_'), '=');
        $transfer = json_encode([
            'protocol' => 'TRIPROOF_TRANSFER_V1',
            'outer_serial' => $serial->outer_serial,
            'unit_hash' => $serial->unit_hash,
            'from_device_token' => 'giver-device',
            'nonce' => $nonce,
            'issued_at' => $issuedAt,
            'proof_tag' => $proofTag,
        ], JSON_THROW_ON_ERROR);

        $this->postJson('/api/v1/verify/online-crosscheck', [
            'device_token' => 'recipient-device',
            'records' => [[
                'sync_uuid' => (string) Str::uuid(),
                'outer_serial' => $serial->outer_serial,
                'inner_serial' => $serial->inner_serial,
                'transfer' => $transfer,
            ]],
        ])->assertOk()->assertJsonPath('results.0.verdict', 'verified_clean');

        $this->assertSame('recipient-device', $serial->fresh()->claimed_device_token);
    }

    public function test_blacklist_delta_is_small_and_carries_a_bloom_filter(): void
    {
        $response = $this->getJson('/api/v1/blacklist/delta')->assertOk();

        $this->assertLessThanOrEqual(50_000, strlen($response->getContent()));
        $response->assertJsonStructure([
            'server_time',
            'entries',
            'bloom_filter' => ['bits_b64', 'm_bits', 'k_hashes', 'seed'],
        ]);
    }
}
