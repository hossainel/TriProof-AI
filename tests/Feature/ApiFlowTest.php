<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->buyer()->create([
            'email' => 'api-buyer@example.test',
            'password_hash' => Hash::make('ApiPass!12345'),
        ]);
        $this->factory = User::factory()->factory('DHK04')->create([
            'email' => 'api-factory@example.test',
            'password_hash' => Hash::make('ApiPass!12345'),
        ]);
    }

    public function test_health_and_well_known_are_public(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
        $this->get('/.well-known/triproof-key.json')
            ->assertOk()
            ->assertJsonPath('data.algorithm', 'Ed25519');
    }

    public function test_orders_require_a_valid_token(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->buyer->email,
            'password' => 'bad-password',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.email.0', 'The provided email or password is incorrect.');
    }

    public function test_end_to_end_api_flow(): void
    {
        $buyerToken = $this->login($this->buyer->email);
        $factoryToken = $this->login($this->factory->email);

        $this->withToken($buyerToken)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.role', 'global_buyer');

        $create = $this->withToken($buyerToken)->postJson('/api/v1/orders', [
            'factory_id' => 'DHK04',
            'authorized_quota' => 10,
            'factory_expiry_days' => 30,
            'retail_expiry_days' => 365,
        ])->assertCreated()->assertJsonPath('success', true);

        $orderId = $create->json('data.order.id');

        $generate = $this->withToken($buyerToken)->postJson('/api/v1/orders/generate-batch', [
            'po_id' => $orderId,
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'count' => 3,
            'prod_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.generated_count', 3);

        $this->assertCount(3, $generate->json('data.units'));

        $this->withToken($factoryToken)->postJson('/api/v1/units/pack-batch', [
            'po_id' => $orderId,
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'unit_start' => 1,
            'unit_end' => 3,
        ])->assertOk()->assertJsonPath('data.packed_count', 3);

        $this->withToken($buyerToken)->getJson("/api/v1/orders/{$orderId}/merkle")
            ->assertOk()
            ->assertJsonPath('data.consistent', true)
            ->assertJsonPath('data.matches', true);

        $this->withToken($buyerToken)
            ->get("/api/v1/orders/{$orderId}/export?format=csv&batch=B01")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $unit = $generate->json('data.units.0');
        $scan = $this->withToken($buyerToken)->postJson('/api/v1/verify/online-crosscheck', [
            'sync_uuid' => (string) Str::uuid(),
            'outer_serial' => $unit['outer_serial'],
            'inner_serial' => $unit['inner_serial'],
            'device_token' => 'device-api-1',
            'scan_mode' => 'online_live',
            'gps_lat' => 23.8103,
            'gps_lng' => 90.4125,
        ])->assertOk()->assertJsonPath('data.results.0.verdict', 'verified_clean');

        $notification = $this->withToken($buyerToken)->getJson('/api/v1/notifications/device-api-1')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
        $this->assertSame(0, $notification->json('data.pagination.total'));

        $this->withToken($factoryToken)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $orderId);

        $this->withToken($factoryToken)->postJson('/api/v1/orders', [
            'factory_id' => 'DHK04',
            'authorized_quota' => 10,
        ])->assertForbidden();

        $this->withToken($buyerToken)->postJson('/api/v1/units/pack-batch', [
            'po_id' => $orderId,
            'machine_id' => 'M01',
            'batch_id' => 'B01',
        ])->assertForbidden();

        $this->withToken($buyerToken)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($buyerToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    private function login(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'ApiPass!12345',
        ])->assertOk();

        return (string) $response->json('data.token');
    }
}
