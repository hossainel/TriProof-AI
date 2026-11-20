<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiAnomalyReport;
use App\Models\BlockchainAnchor;
use App\Models\CartonAggregation;
use App\Models\CartonUnit;
use App\Models\RecallEvent;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Crypto\MerkleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->buyer()->create([
            'email' => 'mcp-buyer@example.test',
            'password_hash' => Hash::make('ApiPass!12345'),
        ]);
    }

    public function test_mcp_requires_authentication_or_a_scoped_api_key(): void
    {
        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();

        config([
            'triproof.mcp.api_key' => 'mcp-test-key',
            'triproof.mcp.api_key_user_id' => $this->user->id,
        ]);

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ], ['X-TriProof-Api-Key' => 'mcp-test-key'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'verify_serial');
    }

    public function test_mcp_initialize_and_tools_list_expose_five_read_only_tools(): void
    {
        $this->withToken($this->token());

        $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ])
            ->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('result.serverInfo.name', 'triproof-ai-mcp');

        $response = $this->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ])->assertOk();

        $this->assertSame([
            'verify_serial',
            'get_batch_anomalies',
            'trace_rework_lineage',
            'check_recall_status',
            'get_merkle_proof',
        ], array_column($response->json('result.tools'), 'name'));
    }

    public function test_verify_serial_tool_runs_deterministic_state_machine(): void
    {
        [$order, $buyer] = $this->makeOrder(8);
        $units = $this->generateSerials($order, $buyer, 2, 'M01', 'B01')['units'];

        $payload = $this->callTool('verify_serial', ['payload_or_serial' => $units[0]['outer_serial']])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $result = $this->toolPayload($payload);

        $this->assertTrue($result['deterministic']);
        $this->assertCount(7, $result['stages']);
        $this->assertNotEmpty($result['key_id']);
        $this->assertSame(0, $result['scan_count']);
        $this->assertSame('verified_clean', $result['verdict']);
    }

    public function test_get_batch_anomalies_tool_returns_bilingual_deterministic_findings(): void
    {
        [$order, $buyer] = $this->makeOrder(8);
        $this->generateSerials($order, $buyer, 2, 'M01', 'B01');

        AiAnomalyReport::query()->create([
            'po_id' => $order->id,
            'batch_id' => 'B01',
            'anomaly_type' => 'clone_cluster',
            'explanation_en' => 'Clone cluster on B01.',
            'explanation_bn' => 'B01 এ ক্লোন ক্লাস্টার।',
            'affected_units_json' => ['ai_severity' => 'high'],
            'status' => 'open',
        ]);

        $payload = $this->callTool('get_batch_anomalies', ['batch_id' => 'B01', 'severity' => 'high'])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $result = $this->toolPayload($payload);

        $this->assertSame(1, $result['count']);
        $this->assertSame('clone_cluster', $result['reports'][0]['detector']);
        $this->assertSame('high', $result['reports'][0]['severity']);
        $this->assertSame('B01 এ ক্লোন ক্লাস্টার।', $result['reports'][0]['summary_bn']);
    }

    public function test_trace_rework_lineage_tool_returns_serial_and_carton_tree(): void
    {
        [$order, $buyer] = $this->makeOrder(8);
        $units = $this->generateSerials($order, $buyer, 2, 'M01', 'B01')['units'];
        $parent = SerialRegistry::query()->where('outer_serial', $units[0]['outer_serial'])->firstOrFail();
        $child = SerialRegistry::query()->where('outer_serial', $units[1]['outer_serial'])->firstOrFail();
        $child->rework_parent_serial = $parent->outer_serial;
        $child->save();

        $carton = CartonAggregation::query()->create([
            'carton_serial' => 'CTN-MCP-01',
            'po_id' => $order->id,
            'factory_id' => 'DHK04',
            'batch_id' => 'B01',
            'unit_count' => 1,
            'carton_merkle_root' => str_repeat('a', 64),
            'carton_signature' => base64_encode('signature'),
            'status' => 'sealed',
            'sealed_by_device' => 'device-1',
        ]);
        CartonUnit::query()->create([
            'carton_id' => $carton->id,
            'serial_registry_id' => $parent->id,
            'leaf_index' => 0,
        ]);

        $payload = $this->callTool('trace_rework_lineage', ['serial' => $child->outer_serial])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $result = $this->toolPayload($payload);

        $this->assertSame($child->outer_serial, $result['root']);
        $this->assertNotEmpty($result['nodes']);
        $this->assertContains('rework', array_column($result['edges'], 'type'));
        $this->assertContains('carton:CTN-MCP-01', array_column($result['nodes'], 'id'));
    }

    public function test_check_recall_status_tool_reports_active_recall_and_blacklist(): void
    {
        [$order, $buyer] = $this->makeOrder(8);
        $units = $this->generateSerials($order, $buyer, 2, 'M01', 'B01')['units'];

        RecallEvent::query()->create([
            'po_id' => $order->id,
            'factory_id' => 'DHK04',
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'affected_count' => 1,
            'reason' => 'MCP recall test',
            'triggered_by' => $this->user->id,
        ]);

        SerialRegistry::query()->where('outer_serial', $units[0]['outer_serial'])
            ->update(['status' => 'recalled']);

        $payload = $this->callTool('check_recall_status', ['batch_id_or_serial' => 'B01'])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $result = $this->toolPayload($payload);

        $this->assertTrue($result['active']);
        $this->assertTrue($result['blacklisted']);
        $this->assertSame(1, $result['recalled_serials']);
        $this->assertCount(1, $result['recall_events']);
    }

    public function test_get_merkle_proof_tool_returns_inclusion_proof_and_anchor(): void
    {
        [$order, $buyer] = $this->makeOrder(8);
        $units = $this->generateSerials($order, $buyer, 3, 'M01', 'B01')['units'];
        $serial = SerialRegistry::query()->where('outer_serial', $units[1]['outer_serial'])->firstOrFail();

        $scan = ScanLog::query()->create([
            'sync_uuid' => (string) Str::uuid(),
            'outer_serial' => $serial->outer_serial,
            'inner_serial' => $serial->inner_serial,
            'device_token' => 'mcp-device',
            'user_role' => UserRole::GlobalBuyer->value,
            'scan_mode' => 'online_live',
            'cloud_cross_check_verdict' => 'verified_clean',
            'scanned_at' => now(),
            'synced_at' => now(),
        ]);

        $root = app(MerkleService::class)->rootForOrder($order);

        BlockchainAnchor::query()->create([
            'anchor_date' => now()->toDateString(),
            'batch_merkle_root' => $root,
            'po_ids_json' => json_encode([$order->id]),
            'provider' => 'local_ledger',
            'tx_hash_or_receipt' => 'MCP-ANCHOR-RECEIPT',
            'status' => 'verified',
        ]);

        $payload = $this->callTool('get_merkle_proof', ['scan_log_id' => $scan->id])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $result = $this->toolPayload($payload);

        $this->assertTrue($result['found']);
        $this->assertTrue($result['proof_valid']);
        $this->assertSame($root, $result['merkle_root']);
        $this->assertSame('MCP-ANCHOR-RECEIPT', $result['anchor']['receipt']);
    }

    private function callTool(string $name, array $arguments)
    {
        return $this->withToken($this->token())->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolPayload($response): array
    {
        $payload = json_decode($response->json('result.content.0.text'), true);

        $this->assertIsArray($payload);

        return $payload;
    }

    private function token(): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'ApiPass!12345',
        ])->assertOk()->json('data.token');
    }
}
