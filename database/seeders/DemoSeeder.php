<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\BatchGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Throwable;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('TRIPROOF_DEMO_PASSWORD');
        if (! $password || strlen($password) < 12) {
            $this->command?->warn('Demo users skipped: set TRIPROOF_DEMO_PASSWORD (12+ chars).');

            return;
        }

        $buyer = User::updateOrCreate(
            ['email' => 'buyer@triproof.local'],
            [
                'name' => 'Apex Global Sourcing',
                'password_hash' => Hash::make($password),
                'role' => UserRole::GlobalBuyer,
                'language_pref' => 'en',
            ]
        );

        $factory = User::updateOrCreate(
            ['email' => 'factory@triproof.local'],
            [
                'name' => 'Dhaka Apparel QA',
                'password_hash' => Hash::make($password),
                'role' => UserRole::FactoryQa,
                'language_pref' => 'bn',
                'factory_id' => 'DHK04',
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@triproof.local'],
            [
                'name' => 'TriProof Operations',
                'password_hash' => Hash::make($password),
                'role' => UserRole::Admin,
                'language_pref' => 'en',
            ]
        );

        $order = PurchaseOrder::firstOrCreate(
            ['po_number' => 'PO-DEMO-2026-001'],
            [
                'buyer_id' => $buyer->id,
                'factory_id' => $factory->factory_id,
                'authorized_quota' => 120,
                'generated_count' => 0,
                'factory_expiry_days' => 45,
                'retail_expiry_days' => 365,
            ]
        );

        PurchaseOrder::firstOrCreate(
            ['po_number' => 'PO-DEMO-2026-002'],
            [
                'buyer_id' => $buyer->id,
                'factory_id' => $factory->factory_id,
                'authorized_quota' => 80,
                'generated_count' => 0,
                'factory_expiry_days' => 30,
                'retail_expiry_days' => 270,
            ]
        );

        try {
            app(BatchGenerationService::class)->generate($order->fresh(), $buyer, [
                'machine_id' => 'M12',
                'batch_id' => 'B09',
                'count' => 12,
                'prod_date' => now()->toDateString(),
            ]);
            $this->command?->info('Demo batch generated (12 units).');
        } catch (Throwable $e) {
            $this->command?->warn('Demo serials were not generated: '.$e->getMessage());
        }
    }
}
