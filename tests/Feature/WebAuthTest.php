<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_login_redirects_to_the_dashboard(): void
    {
        $user = User::factory()->buyer()->create([
            'email' => 'buyer-login@example.test',
            'password_hash' => Hash::make('Correct!12345'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'Correct!12345',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->buyer()->create(['email' => 'buyer-bad@example.test']);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_each_role_can_open_its_dashboard(): void
    {
        $this->actingAs(User::factory()->buyer()->create())->get('/dashboard')->assertOk();
        $this->actingAs(User::factory()->factory('DHK04')->create())->get('/dashboard')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertOk();
    }

    public function test_factories_cannot_create_or_generate_orders(): void
    {
        $factory = User::factory()->factory('DHK04')->create();
        [$order] = [$this->makeOrderFor($factory)];

        $this->actingAs($factory)->post('/orders', [
            'factory_id' => 'DHK04',
            'authorized_quota' => 10,
            'factory_expiry_days' => 30,
            'retail_expiry_days' => 180,
        ])->assertForbidden();

        $this->actingAs($factory)->post("/orders/{$order->id}/generate", [
            'machine_id' => 'M01',
            'batch_id' => 'B01',
            'count' => 1,
        ])->assertForbidden();
    }

    public function test_buyers_cannot_pack_or_reconcile_rework(): void
    {
        $buyer = User::factory()->buyer()->create();
        $order = PurchaseOrder::factory()->create([
            'buyer_id' => $buyer->id,
            'factory_id' => 'DHK04',
        ]);

        $this->actingAs($buyer)->post("/orders/{$order->id}/pack", [
            'machine_id' => 'M01',
            'batch_id' => 'B01',
        ])->assertForbidden();

        $this->actingAs($buyer)->post('/rework', [])->assertForbidden();
    }

    public function test_locale_switch_is_stored_for_guests_and_users(): void
    {
        $this->from('/login')->post('/language', ['locale' => 'bn'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'bn');

        $buyer = User::factory()->buyer()->create();
        $this->actingAs($buyer)
            ->from('/dashboard')
            ->post('/language', ['locale' => 'bn'])
            ->assertRedirect('/dashboard');

        $this->assertSame('bn', $buyer->fresh()->language_pref);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $buyer = User::factory()->buyer()->create();

        $this->actingAs($buyer)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function makeOrderFor(User $factory): PurchaseOrder
    {
        $buyer = User::factory()->buyer()->create();

        return PurchaseOrder::factory()->create([
            'buyer_id' => $buyer->id,
            'factory_id' => $factory->factory_id,
        ]);
    }
}
