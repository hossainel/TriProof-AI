<?php

namespace Tests\Feature;

use App\Models\Factory;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(string $factoryCode): array
    {
        return [
            'factory_id' => $factoryCode,
            'authorized_quota' => 100,
            'factory_expiry_days' => 30,
            'retail_expiry_days' => 365,
            'dialog_id' => 'create-order-dialog',
        ];
    }

    public function test_admin_can_create_an_order_for_an_active_factory_without_a_factory_qa_user(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create();
        Factory::query()->create([
            'code' => 'BD20',
            'name_en' => 'No QA Plant',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('orders.store'), $this->validPayload('bd20') + [
            'buyer_id' => $buyer->id,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_orders', [
            'buyer_id' => $buyer->id,
            'factory_id' => 'BD20',
        ]);
        $this->assertDatabaseMissing('users', [
            'role' => 'factory_qa',
            'factory_id' => 'BD20',
        ]);
    }

    public function test_buyer_can_create_an_order_for_an_active_factory_without_a_factory_qa_user(): void
    {
        $buyer = User::factory()->buyer()->create();
        Factory::query()->create([
            'code' => 'BD21',
            'name_en' => 'Buyer Plant',
            'is_active' => true,
        ]);

        $response = $this->actingAs($buyer)->post(route('orders.store'), $this->validPayload('BD21'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_orders', [
            'buyer_id' => $buyer->id,
            'factory_id' => 'BD21',
        ]);
        $this->assertDatabaseMissing('users', [
            'role' => 'factory_qa',
            'factory_id' => 'BD21',
        ]);
    }

    public function test_unknown_factory_is_rejected_and_the_create_dialog_reopens(): void
    {
        $buyer = User::factory()->buyer()->create();

        $response = $this->actingAs($buyer)
            ->from(route('dashboard'))
            ->post(route('orders.store'), $this->validPayload('UNKNOWN'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasErrors([
            'factory_id' => __('triproof.validation.factory_not_registered'),
        ]);
        $response->assertSessionHasInput('dialog_id', 'create-order-dialog');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_inactive_factory_is_rejected_and_the_create_dialog_reopens(): void
    {
        $buyer = User::factory()->buyer()->create();
        Factory::query()->create([
            'code' => 'BD22',
            'name_en' => 'Inactive Plant',
            'is_active' => false,
        ]);

        $response = $this->actingAs($buyer)
            ->from(route('dashboard'))
            ->post(route('orders.store'), $this->validPayload('BD22'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasErrors([
            'factory_id' => __('triproof.validation.factory_not_registered'),
        ]);
        $response->assertSessionHasInput('dialog_id', 'create-order-dialog');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_dashboard_backfills_missing_factories_as_active_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->buyer()->create();
        PurchaseOrder::factory()->create([
            'buyer_id' => $buyer->id,
            'factory_id' => 'LEGACY1',
        ]);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->assertDatabaseHas('factories', ['code' => 'LEGACY1', 'is_active' => true]);
        $this->assertDatabaseHas('factories', ['code' => 'DHK04', 'is_active' => true]);
    }
}
