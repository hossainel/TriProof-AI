<?php

namespace Tests\Feature;

use App\Enums\SerialStatus;
use App\Models\AiAnomalyReport;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\ScanLog;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\Crypto\SerialCryptoService;
use App\Services\VerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class VerificationFlowTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    private PurchaseOrder $order;

    private User $buyer;

    /** @var Collection<int, SerialRegistry> */
    private $serials;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');

        [$this->order, $this->buyer] = $this->makeOrder(20);
        $this->generateSerials($this->order, $this->buyer, 8);
        $this->serials = $this->order->serials()->orderBy('unit_number')->get()->values();
    }

    public function test_first_claim_links_the_device_and_owner_repeat_is_idempotent(): void
    {
        $serial = $this->serials[0];

        $first = $this->scan($serial, 'device-owner', 23.8103, 90.4125, '2026-11-18T10:00:00+06:00');
        $this->assertSame('verified_clean', $first['results'][0]['verdict']);
        $this->assertTrue($first['results'][0]['claimed_now']);

        $serial->refresh();
        $this->assertSame('claimed_genuine', $serial->status->value);
        $this->assertSame('device-owner', $serial->claimed_device_token);
        $this->assertNotNull($serial->claimed_at);

        $repeat = $this->scan($serial, 'device-owner', 23.9000, 90.5000, '2026-11-18T10:01:00+06:00');
        $this->assertSame('owner_repeat_ok', $repeat['results'][0]['verdict']);
        $this->assertFalse($repeat['results'][0]['claimed_now']);
        $this->assertSame('device-owner', $serial->fresh()->claimed_device_token);
    }

    public function test_second_device_inside_grace_is_allowed_but_outside_grace_flags_a_clone(): void
    {
        $serial = $this->serials[1];
        $this->scan($serial, 'device-owner', 23.8103, 90.4125, '2026-11-18T10:00:00+06:00');

        $grace = $this->scan($serial, 'device-store', 23.8104, 90.4126, '2026-11-18T10:01:00+06:00');
        $this->assertSame('store_grace_ok', $grace['results'][0]['verdict']);
        $this->assertSame('claimed_genuine', $serial->fresh()->status->value);

        $clone = $this->scan($serial, 'device-store', 23.9000, 90.5000, '2026-11-18T10:02:00+06:00');
        $result = $clone['results'][0];

        $this->assertSame('clone_detected', $result['verdict']);
        $this->assertSame('cloned_flagged', $serial->fresh()->status->value);
        $this->assertCount(2, $clone['feedback_notifications']);
        $this->assertSame(2, Notification::query()->where('severity', 'critical_clone')->count());
        $this->assertSame(1, AiAnomalyReport::query()->where('anomaly_type', 'clone_cluster')->count());

        $report = AiAnomalyReport::query()->where('anomaly_type', 'clone_cluster')->firstOrFail();
        $this->assertSame(1, $report->affected_units_json['count']);
        $this->assertSame($serial->outer_serial, $report->affected_units_json['serials'][0]['outer_serial']);
    }

    public function test_recalled_batch_is_reported_before_claiming(): void
    {
        $serial = $this->serials[2];
        $serial->update(['status' => SerialStatus::Recalled->value]);

        $result = $this->scan($serial, 'device-buyer', 23.8103, 90.4125, '2026-11-18T10:00:00+06:00');

        $this->assertSame('recalled_batch', $result['results'][0]['verdict']);
        $this->assertFalse($result['results'][0]['claimed_now']);
        $this->assertNull($serial->fresh()->claimed_device_token);
        $this->assertSame(1, Notification::query()->where('severity', 'recall_alert')->count());
    }

    public function test_expired_units_are_reported_without_claiming(): void
    {
        $serial = $this->serials[3];
        $serial->update([
            'status' => SerialStatus::Generated->value,
            'exp_date' => '2026-11-17',
        ]);

        $result = $this->scan($serial, 'device-buyer', 23.8103, 90.4125, '2026-11-18T10:00:00+06:00');

        $this->assertSame('expired', $result['results'][0]['verdict']);
        $this->assertFalse($result['results'][0]['claimed_now']);
        $this->assertNull($serial->fresh()->claimed_device_token);
    }

    public function test_forged_signatures_are_rejected_and_alerted(): void
    {
        $serial = $this->serials[4];
        $tampered = substr($serial->inner_serial, 0, -4).'AAAA';

        $result = $this->scan($serial, 'device-buyer', null, null, '2026-11-18T10:00:00+06:00', $tampered);

        $this->assertSame('forged_signature', $result['results'][0]['verdict']);
        $this->assertFalse($result['results'][0]['signature_valid']);
        $this->assertSame(1, Notification::query()->where('severity', 'warning')->count());
        $this->assertNull($serial->fresh()->claimed_device_token);
    }

    public function test_sync_uuid_replay_is_idempotent_and_does_not_duplicate_scans(): void
    {
        $serial = $this->serials[5];
        $uuid = (string) Str::uuid();
        $log = $this->log($serial, 'device-owner', 23.8103, 90.4125, '2026-11-18T10:00:00+06:00', null, $uuid);

        $first = app(VerificationService::class)->crossCheck([$log], $this->buyer);
        $second = app(VerificationService::class)->crossCheck([$log], $this->buyer);

        $this->assertFalse($first['results'][0]['duplicate']);
        $this->assertTrue($second['results'][0]['duplicate']);
        $this->assertSame(1, ScanLog::query()->where('sync_uuid', $uuid)->count());
    }

    public function test_unknown_but_correctly_signed_serial_is_not_accepted(): void
    {
        $crypto = app(SerialCryptoService::class);
        $outer = 'DHK04-M99-B99-000001|20261118|20271118';
        $inner = $crypto->sign($outer);

        $result = app(VerificationService::class)->crossCheck([[
            'sync_uuid' => (string) Str::uuid(),
            'outer_serial' => $outer,
            'inner_serial' => $inner,
            'device_token' => 'device-unknown',
            'scan_mode' => 'online_live',
            'scanned_at' => '2026-11-18T10:00:00+06:00',
        ]], $this->buyer);

        $this->assertSame('forged_signature', $result['results'][0]['verdict']);
        $this->assertFalse($result['results'][0]['registered']);
    }

    private function scan(
        SerialRegistry $serial,
        string $device,
        ?float $lat,
        ?float $lng,
        string $scannedAt,
        ?string $inner = null,
    ): array {
        return app(VerificationService::class)->crossCheck([
            $this->log($serial, $device, $lat, $lng, $scannedAt, $inner),
        ], $this->buyer);
    }

    private function log(
        SerialRegistry $serial,
        string $device,
        ?float $lat,
        ?float $lng,
        string $scannedAt,
        ?string $inner = null,
        ?string $uuid = null,
    ): array {
        return [
            'sync_uuid' => $uuid ?? (string) Str::uuid(),
            'outer_serial' => $serial->outer_serial,
            'inner_serial' => $inner ?? $serial->inner_serial,
            'device_token' => $device,
            'scan_mode' => 'online_live',
            'gps_lat' => $lat,
            'gps_lng' => $lng,
            'scanned_at' => $scannedAt,
        ];
    }
}
