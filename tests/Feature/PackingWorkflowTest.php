<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Services\PackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TriProofFixtures;
use Tests\TestCase;

class PackingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use TriProofFixtures;

    public function test_dashboard_exposes_remaining_ranges_and_all_existing_batches(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(20);
        $this->generateSerials($order, $buyer, 5, 'M01', 'B01');
        $this->generateSerials($order, $buyer, 4, 'M01', 'B02');

        app(PackingService::class)->pack($factory, [
            'po_id' => $order->id,
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'unit_start' => 1,
            'unit_end' => 2,
        ]);
        app(PackingService::class)->pack($factory, [
            'po_id' => $order->id,
            'machine_id' => 'M01',
            'batch_id' => 'B02',
        ]);

        $response = $this->actingAs($buyer)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="triproof-packable-data"', false);
        $response->assertViewHas('packableSummary', function (array $summary) use ($order) {
            $entry = $summary[$order->id] ?? null;

            $this->assertNotNull($entry);
            $this->assertSame(3, $entry['total_remaining']);
            $this->assertCount(1, $entry['batches']);
            $this->assertSame([
                'machine_id' => 'M01',
                'batch_id' => 'B01',
                'remaining_count' => 3,
                'min_unit' => 3,
                'max_unit' => 5,
            ], $entry['batches'][0]);
            $this->assertCount(2, $entry['all_batches']);

            $packedBatch = collect($entry['all_batches'])->firstWhere('batch_id', 'B02');
            $this->assertNotNull($packedBatch);
            $this->assertSame(0, $packedBatch['remaining_count']);
            $this->assertNull($packedBatch['min_unit']);
            $this->assertNull($packedBatch['max_unit']);

            return true;
        });
    }

    public function test_pack_warning_lists_available_batch_ranges_when_no_units_match(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(20);
        $this->generateSerials($order, $buyer, 5, 'M01', 'B01');
        $this->generateSerials($order, $buyer, 2, 'M02', 'B07');

        $response = $this->actingAs($factory)
            ->from(route('dashboard'))
            ->post(route('orders.pack', $order), [
                'machine_id' => 'M01',
                'batch_id' => 'B99',
                'unit_start' => 1,
                'unit_end' => 2,
            ]);

        $batches = __('triproof.validation.pack_available_batch', [
            'machine' => 'M01',
            'batch' => 'B01',
            'count' => 5,
            'min' => 1,
            'max' => 5,
        ]).'; '.__('triproof.validation.pack_available_batch', [
            'machine' => 'M02',
            'batch' => 'B07',
            'count' => 2,
            'min' => 1,
            'max' => 2,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasErrors([
            'pack' => __('triproof.validation.pack_no_match', [
                'po' => $order->po_number,
                'batches' => $batches,
            ]),
        ]);
    }

    public function test_pack_warning_reports_when_every_generated_unit_is_already_packed(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 3, 'M01', 'B01');

        app(PackingService::class)->pack($factory, [
            'po_id' => $order->id,
            'machine_id' => 'M01',
            'batch_id' => 'B01',
        ]);

        $response = $this->actingAs($factory)
            ->from(route('dashboard'))
            ->post(route('orders.pack', $order), [
                'machine_id' => 'M01',
                'batch_id' => 'B01',
            ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasErrors([
            'pack' => __('triproof.validation.pack_all_packed', [
                'po' => $order->po_number,
            ]),
        ]);
    }

    public function test_pack_warning_is_translated_into_bangla(): void
    {
        [$order, $buyer, $factory] = $this->makeOrder(10);
        $this->generateSerials($order, $buyer, 2, 'M01', 'B01');

        app()->setLocale('bn');

        try {
            app(PackingService::class)->pack($factory, [
                'po_id' => $order->id,
                'machine_id' => 'M01',
                'batch_id' => 'B99',
            ]);
            $this->fail('Expected a BusinessRuleException.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('nothing_to_pack', $exception->errorCode);
            $this->assertStringContainsString($order->po_number, $exception->getMessage());
            $this->assertStringContainsString('প্যাক করার জন্য উপলব্ধ', $exception->getMessage());
            $this->assertStringContainsString('মেশিন M01', $exception->getMessage());
        }
    }
}
