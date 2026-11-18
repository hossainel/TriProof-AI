<?php

namespace Tests\Feature;

use App\Enums\NotificationSeverity;
use App\Enums\SerialStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Notification;
use App\Models\RecallEvent;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\RecallService;
use App\Services\ReworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class RecallReworkTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    public function test_precision_recall_updates_the_batch_and_notifies_claimed_and_scanned_devices(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(20);
        $this->generateSerials($order, $buyer, 5);

        $serials = $order->serials()->orderBy('unit_number')->get();
        $serials[0]->update([
            'status' => SerialStatus::ClaimedGenuine->value,
            'claimed_device_token' => 'device-claimed',
            'claimed_at' => now(),
        ]);

        ScanLog::create([
            'sync_uuid' => (string) Str::uuid(),
            'outer_serial' => $serials[1]->outer_serial,
            'inner_serial' => $serials[1]->inner_serial,
            'device_token' => 'device-scanned',
            'user_role' => $buyer->role,
            'scan_mode' => 'offline_synced',
            'cloud_cross_check_verdict' => 'verified_clean',
            'scanned_at' => now()->subHour(),
            'synced_at' => now(),
        ]);

        $result = app(RecallService::class)->trigger($factory, [
            'factory_id' => 'DHK04',
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'reason' => 'Needle contamination drill',
        ]);

        $this->assertSame(5, $result['affected_units']);
        $this->assertSame(2, $result['devices_notified']);
        $this->assertSame('DHK04-M01-B01', $result['batch']);
        $this->assertSame(5, $order->serials()->where('status', SerialStatus::Recalled->value)->count());

        $event = RecallEvent::query()->firstOrFail();
        $this->assertSame($order->id, $event->po_id);
        $this->assertSame(5, $event->affected_count);
        $this->assertSame($factory->id, $event->triggered_by);

        $this->assertSame(2, Notification::query()->where('severity', NotificationSeverity::RecallAlert->value)->count());
        $this->assertDatabaseHas('notifications', ['device_token' => 'device-claimed']);
        $this->assertDatabaseHas('notifications', ['device_token' => 'device-scanned']);
    }

    public function test_a_factory_cannot_recall_another_factorys_batch(): void
    {
        [$order, $buyer] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 3);
        $otherFactory = User::factory()->factory('OTHER')->create();

        $this->expectException(BusinessRuleException::class);

        app(RecallService::class)->trigger($otherFactory, [
            'factory_id' => 'DHK04',
            'machine_id' => 'M01',
            'batch_id' => 'B01',
        ]);
    }

    public function test_rework_lineage_links_parent_and_child_and_is_idempotent(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(20);
        $parent = $this->generateSerials($order, $buyer, 1, 'M01', 'P01')['units'][0];
        $child = $this->generateSerials($order, $buyer, 1, 'M01', 'C01')['units'][0];

        $entry = [
            'parent_outer_serial' => $parent['outer_serial'],
            'child_outer_serial' => $child['outer_serial'],
            'action' => 'rework',
            'line_id' => 'LINE-01',
            'note' => 'Replacement label verified.',
        ];

        $first = app(ReworkService::class)->reconcile($factory, [$entry]);
        $second = app(ReworkService::class)->reconcile($factory, [$entry]);

        $this->assertSame(1, $first['counts']['linked']);
        $this->assertSame(1, $second['counts']['already_linked']);
        $this->assertSame($parent['outer_serial'], SerialRegistry::query()
            ->where('outer_serial', $child['outer_serial'])
            ->value('rework_parent_serial'));
        $this->assertStringContainsString('Reconciled', $first['summary_en']);
        $this->assertStringContainsString('রিওয়ার্ক', $first['summary_bn']);
    }

    public function test_rework_detects_missing_parent_and_records_a_gap(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(20);
        $child = $this->generateSerials($order, $buyer, 1, 'M01', 'C02')['units'][0];
        $missingParent = 'DHK04-M01-MISSING-000001|20260101|20270101';

        $result = app(ReworkService::class)->reconcile($factory, [[
            'parent_outer_serial' => $missingParent,
            'child_outer_serial' => $child['outer_serial'],
        ]]);

        $this->assertSame(1, $result['counts']['gaps']);
        $this->assertSame('gap', $result['results'][0]['status']);
        $this->assertDatabaseHas('ai_anomaly_reports', ['anomaly_type' => 'rework_gap']);
        $this->assertNull(SerialRegistry::query()->where('outer_serial', $child['outer_serial'])->value('rework_parent_serial'));
    }

    public function test_rework_rejects_a_cycle(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(20);
        $units = $this->generateSerials($order, $buyer, 3, 'M01', 'C03')['units'];

        app(ReworkService::class)->reconcile($factory, [[
            'parent_outer_serial' => $units[1]['outer_serial'],
            'child_outer_serial' => $units[0]['outer_serial'],
        ]]);

        $result = app(ReworkService::class)->reconcile($factory, [[
            'parent_outer_serial' => $units[0]['outer_serial'],
            'child_outer_serial' => $units[1]['outer_serial'],
        ]]);

        $this->assertSame('rejected', $result['results'][0]['status']);
        $this->assertNull(SerialRegistry::query()->where('outer_serial', $units[1]['outer_serial'])->value('rework_parent_serial'));
        $this->assertDatabaseHas('ai_anomaly_reports', ['anomaly_type' => 'rework_gap']);
    }

    public function test_rework_flags_a_tampered_signature_as_a_gap(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(20);
        $parent = $this->generateSerials($order, $buyer, 1, 'M01', 'P04')['units'][0];
        $child = $this->generateSerials($order, $buyer, 1, 'M01', 'C04')['units'][0];
        SerialRegistry::query()->where('outer_serial', $child['outer_serial'])->update([
            'inner_serial' => substr($child['inner_serial'], 0, -4).'AAAA',
        ]);

        $result = app(ReworkService::class)->reconcile($factory, [[
            'parent_outer_serial' => $parent['outer_serial'],
            'child_outer_serial' => $child['outer_serial'],
        ]]);

        $this->assertSame('gap', $result['results'][0]['status']);
        $this->assertNull(SerialRegistry::query()->where('outer_serial', $child['outer_serial'])->value('rework_parent_serial'));
    }

    public function test_rework_rejects_parent_and_child_from_different_factories(): void
    {
        [$order, $buyer] = $this->makeOrder(20);
        $child = $this->generateSerials($order, $buyer, 1, 'M01', 'C05')['units'][0];

        [$otherOrder, $otherBuyer] = $this->makeOrder(20, ['factory_id' => 'DHK99']);
        $parent = $this->generateSerials($otherOrder, $otherBuyer, 1, 'M01', 'P05')['units'][0];

        $admin = User::factory()->admin()->create();

        $result = app(ReworkService::class)->reconcile($admin, [[
            'parent_outer_serial' => $parent['outer_serial'],
            'child_outer_serial' => $child['outer_serial'],
        ]]);

        $this->assertSame('gap', $result['results'][0]['status']);
        $this->assertNull(SerialRegistry::query()->where('outer_serial', $child['outer_serial'])->value('rework_parent_serial'));
    }

    public function test_only_admin_and_factory_can_reconcile_rework(): void
    {
        [$order] = $this->makeOrder(20);
        $buyer = $order->buyer;

        $this->expectException(BusinessRuleException::class);

        app(ReworkService::class)->reconcile($buyer, [[
            'parent_outer_serial' => 'DHK04-M01-B01-000001|20261118|20271118',
            'child_outer_serial' => 'DHK04-M01-B01-000002|20261118|20271118',
        ]]);
    }
}
