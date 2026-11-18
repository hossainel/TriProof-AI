<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'po_number' => 'PO-'.fake()->unique()->numerify('######'),
            'buyer_id' => User::factory()->buyer(),
            'factory_id' => 'DHK04',
            'authorized_quota' => 100,
            'generated_count' => 0,
            'factory_expiry_days' => 45,
            'retail_expiry_days' => 365,
            'merkle_root_hash' => null,
        ];
    }
}
