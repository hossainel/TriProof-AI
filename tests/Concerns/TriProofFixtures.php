<?php

namespace Tests\Concerns;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\BatchGenerationService;

trait TriProofFixtures
{
    protected function makeOrder(int $quota = 20, array $overrides = []): array
    {
        $buyer = User::factory()->buyer()->create();
        $factory = User::factory()->factory('DHK04')->create();

        $order = PurchaseOrder::factory()->create(array_merge([
            'buyer_id' => $buyer->id,
            'factory_id' => 'DHK04',
            'authorized_quota' => $quota,
            'generated_count' => 0,
            'factory_expiry_days' => 45,
            'retail_expiry_days' => 365,
        ], $overrides));

        return [$order, $buyer, $factory];
    }

    protected function generateSerials(
        PurchaseOrder $order,
        User $actor,
        int $count = 5,
        string $machine = 'M01',
        string $batch = 'B01',
        ?string $prodDate = null,
    ): array {
        return app(BatchGenerationService::class)->generate($order, $actor, [
            'machine_id' => $machine,
            'batch_id' => $batch,
            'unit_start' => 1,
            'count' => $count,
            'prod_date' => $prodDate ?? now()->toDateString(),
        ]);
    }
}
