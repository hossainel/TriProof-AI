<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\SerialRegistry;
use App\Models\User;
use App\Services\BatchGenerationService;
use App\Services\Crypto\MerkleService;
use App\Services\Crypto\SerialCryptoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class BatchGenerationTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    public function test_generation_signs_exactly_the_requested_units_and_persists_a_merkle_root(): void
    {
        [$order, $buyer] = $this->makeOrder(10);

        $result = $this->generateSerials($order, $buyer, 4);

        $order->refresh();
        $serials = $order->serials()->orderBy('unit_number')->get();

        $this->assertSame(4, $result['count']);
        $this->assertSame(1, $result['unit_start']);
        $this->assertSame(4, $result['unit_end']);
        $this->assertSame(4, $order->generated_count);
        $this->assertCount(4, $serials);
        $this->assertNotNull($order->merkle_root_hash);
        $this->assertCount(4, $result['units']);

        $crypto = app(SerialCryptoService::class);
        foreach ($serials as $index => $serial) {
            $this->assertSame($result['units'][$index]['outer_serial'], $serial->outer_serial);
            $this->assertTrue($crypto->verify($serial->outer_serial, $serial->inner_serial));
            $this->assertSame($crypto->unitHash($serial->outer_serial, $serial->inner_serial), $serial->unit_hash);
            $this->assertSame('generated', $serial->status->value);
        }

        $root = app(MerkleService::class)->root($serials->pluck('unit_hash')->all());
        $this->assertSame($root, $order->merkle_root_hash);
        $this->assertSame($root, $result['merkle_root']);
    }

    public function test_over_quota_generation_is_rejected_without_partial_writes(): void
    {
        [$order, $buyer] = $this->makeOrder(5);
        $this->generateSerials($order, $buyer, 4);

        $before = $order->fresh();

        try {
            $this->generateSerials($order->fresh(), $buyer, 2, 'M02', 'B02');
            $this->fail('Expected quota rejection.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('insufficient_quota', $e->errorCode);
            $this->assertSame(409, $e->httpStatus);
        }

        $after = $order->fresh();

        $this->assertSame(4, $after->generated_count);
        $this->assertSame(4, SerialRegistry::query()->where('po_id', $order->id)->count());
        $this->assertSame($before->merkle_root_hash, $after->merkle_root_hash);
    }

    public function test_factories_cannot_generate_signatures(): void
    {
        [$order, , $factory] = $this->makeOrder(10);

        $this->expectException(BusinessRuleException::class);

        app(BatchGenerationService::class)->generate($order, $factory, [
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'count' => 1,
            'prod_date' => now()->toDateString(),
        ]);
    }

    public function test_a_buyer_cannot_generate_for_another_buyers_order(): void
    {
        [$order] = $this->makeOrder(10);
        $otherBuyer = User::factory()->buyer()->create();

        $this->expectException(BusinessRuleException::class);

        app(BatchGenerationService::class)->generate($order, $otherBuyer, [
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'count' => 1,
            'prod_date' => now()->toDateString(),
        ]);
    }

    public function test_overlapping_serial_ranges_do_not_consume_quota(): void
    {
        [$order, $buyer] = $this->makeOrder(20);
        $this->generateSerials($order, $buyer, 5, 'M01', 'B01');

        try {
            app(BatchGenerationService::class)->generate($order->fresh(), $buyer, [
                'machine_id' => 'M01',
                'batch_id' => 'B01',
                'unit_start' => 4,
                'count' => 3,
                'prod_date' => now()->toDateString(),
            ]);
            $this->fail('Expected a serial conflict.');
        } catch (BusinessRuleException $e) {
            $this->assertContains($e->errorCode, ['unit_conflict', 'serial_conflict']);
        }

        $this->assertSame(5, $order->fresh()->generated_count);
        $this->assertSame(5, SerialRegistry::query()->where('po_id', $order->id)->count());
    }

    public function test_quota_is_enforced_transactionally_after_a_failed_conflict(): void
    {
        [$order, $buyer] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 8, 'M01', 'B01');

        try {
            app(BatchGenerationService::class)->generate($order->fresh(), $buyer, [
                'machine_id' => 'M01',
                'batch_id' => 'B01',
                'unit_start' => 1,
                'count' => 2,
                'prod_date' => now()->toDateString(),
            ]);
        } catch (BusinessRuleException) {
        }

        $this->assertSame(8, $order->fresh()->generated_count);
        $this->assertSame(8, SerialRegistry::query()->where('po_id', $order->id)->count());
        $this->assertSame(10, PurchaseOrder::query()->whereKey($order->id)->value('authorized_quota'));
    }
}
