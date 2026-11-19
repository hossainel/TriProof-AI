<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Factory;
use App\Models\PurchaseOrder;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminDirectoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function signInAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        return $admin;
    }

    /** @test */
    public function admin_can_open_the_directory_page(): void
    {
        $this->signInAsAdmin();

        $response = $this->get(route('admin.manage'));

        $response->assertOk();
        $response->assertSee(__('triproof.admin.title'));
        $response->assertSee(__('triproof.admin.buyers.title'));
        $response->assertSee(__('triproof.admin.factories.title'));
        $response->assertSee(__('triproof.admin.operators.title'));
        $response->assertSee(__('triproof.admin.settings.title'));
    }

    /** @test */
    public function non_admin_cannot_access_the_directory_page(): void
    {
        $buyer = User::factory()->buyer()->create();

        $this->actingAs($buyer)->get(route('admin.manage'))
            ->assertForbidden();
    }

    /** @test */
    public function admin_can_create_a_global_buyer(): void
    {
        $this->signInAsAdmin();

        $response = $this->post(route('admin.buyers.store'), [
            'name' => 'Test Buyer',
            'email' => 'buyer@example.test',
            'password' => 'Correct!12345',
            'language_pref' => 'en',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'buyer@example.test',
            'role' => UserRole::GlobalBuyer->value,
        ]);
        $this->assertTrue(Hash::check('Correct!12345', User::where('email', 'buyer@example.test')->value('password_hash')));
    }

    /** @test */
    public function admin_can_update_a_global_buyer(): void
    {
        $buyer = User::factory()->buyer()->create(['name' => 'Old Name']);
        $this->signInAsAdmin();

        $response = $this->put(route('admin.buyers.update', $buyer), [
            'name' => 'New Name',
            'email' => $buyer->email,
            'language_pref' => 'bn',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $this->assertDatabaseHas('users', [
            'id' => $buyer->id,
            'name' => 'New Name',
            'language_pref' => 'bn',
        ]);
    }

    /** @test */
    public function admin_cannot_delete_a_buyer_that_owns_orders(): void
    {
        $buyer = User::factory()->buyer()->create();
        PurchaseOrder::factory()->create(['buyer_id' => $buyer->id]);
        $this->signInAsAdmin();

        $response = $this->delete(route('admin.buyers.destroy', $buyer));

        $response->assertSessionHasErrors('buyer');
        $this->assertDatabaseHas('users', ['id' => $buyer->id]);
    }

    /** @test */
    public function admin_can_delete_a_buyer_without_orders(): void
    {
        $buyer = User::factory()->buyer()->create();
        $this->signInAsAdmin();

        $this->delete(route('admin.buyers.destroy', $buyer))
            ->assertRedirect(route('admin.manage'));

        $this->assertDatabaseMissing('users', ['id' => $buyer->id]);
    }

    /** @test */
    public function admin_can_create_a_factory(): void
    {
        $this->signInAsAdmin();

        $response = $this->post(route('admin.factories.store'), [
            'code' => 'BD06',
            'name_en' => 'Dhaka Plant',
            'name_bn' => 'ঢাকা প্ল্যান্ট',
            'location' => 'Dhaka',
            'contact_email' => 'plant@example.test',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $this->assertDatabaseHas('factories', [
            'code' => 'BD06',
            'name_en' => 'Dhaka Plant',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function admin_can_create_a_factory_inactive(): void
    {
        $this->signInAsAdmin();

        $response = $this->post(route('admin.factories.store'), [
            'code' => 'BD09',
            'name_en' => 'Inactive Plant',
            'name_bn' => 'নিষ্ক্রিয় প্ল্যান্ট',
            'location' => 'Chattogram',
            'contact_email' => 'inactive@example.test',
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $this->assertDatabaseHas('factories', [
            'code' => 'BD09',
            'name_en' => 'Inactive Plant',
            'is_active' => false,
        ]);
    }

    /** @test */
    public function admin_can_create_a_factory_without_is_active_defaults_to_false(): void
    {
        $this->signInAsAdmin();

        $response = $this->post(route('admin.factories.store'), [
            'code' => 'BD10',
            'name_en' => 'No Checkbox Plant',
            'name_bn' => 'চেকবক্সহীন প্ল্যান্ট',
            'location' => 'Sylhet',
            'contact_email' => 'nocheck@example.test',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $this->assertDatabaseHas('factories', [
            'code' => 'BD10',
            'name_en' => 'No Checkbox Plant',
            'is_active' => false,
        ]);
    }

    /** @test */
    public function admin_can_update_factory_is_active(): void
    {
        $factory = Factory::query()->create([
            'code' => 'BD11',
            'name_en' => 'Updatable Plant',
            'is_active' => true,
        ]);
        $this->signInAsAdmin();

        $response = $this->put(route('admin.factories.update', $factory), [
            'name_en' => 'Updatable Plant Updated',
            'name_bn' => 'আপডেটযোগ্য প্ল্যান্ট আপডেট করা হয়েছে',
            'location' => 'Rajshahi',
            'contact_email' => 'update@example.test',
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $this->assertDatabaseHas('factories', [
            'id' => $factory->id,
            'code' => 'BD11',
            'is_active' => false,
        ]);
    }

    /** @test */
    public function admin_can_update_factory_remains_active(): void
    {
        $factory = Factory::query()->create([
            'code' => 'BD12',
            'name_en' => 'Remains Active Plant',
            'is_active' => false,
        ]);
        $this->signInAsAdmin();

        $response = $this->put(route('admin.factories.update', $factory), [
            'name_en' => 'Remains Active Plant Updated',
            'name_bn' => 'সক্রিয় থাকা প্ল্যান্ট আপডেট করা হয়েছে',
            'location' => 'Khulna',
            'contact_email' => 'remain@example.test',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $this->assertDatabaseHas('factories', [
            'id' => $factory->id,
            'code' => 'BD12',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function admin_cannot_delete_a_factory_that_is_referenced(): void
    {
        $factory = Factory::query()->create([
            'code' => 'BD07',
            'name_en' => 'Referenced Plant',
            'is_active' => true,
        ]);
        User::factory()->factory($factory->code)->create();
        $this->signInAsAdmin();

        $response = $this->delete(route('admin.factories.destroy', $factory));

        $response->assertSessionHasErrors('factory');
        $this->assertDatabaseHas('factories', ['id' => $factory->id]);
    }

    /** @test */
    public function admin_can_create_a_factory_qa_operator(): void
    {
        Factory::query()->create([
            'code' => 'BD08',
            'name_en' => 'Operator Plant',
            'is_active' => true,
        ]);
        $this->signInAsAdmin();

        $response = $this->post(route('admin.operators.store'), [
            'name' => 'QA Operator',
            'email' => 'operator@example.test',
            'password' => 'Correct!12345',
            'factory_id' => 'BD08',
            'language_pref' => 'en',
        ]);

        $response->assertRedirect(route('admin.manage'));
        $this->assertDatabaseHas('users', [
            'email' => 'operator@example.test',
            'role' => UserRole::FactoryQa->value,
            'factory_id' => 'BD08',
        ]);
    }

    /** @test */
    public function admin_can_update_platform_settings(): void
    {
        $this->signInAsAdmin();

        $this->put(route('admin.settings.update'), [
            'organization_name' => 'Test Org',
            'support_email' => 'support@example.test',
            'default_factory_expiry_days' => 30,
            'default_retail_expiry_days' => 180,
        ])->assertRedirect(route('admin.manage'));

        $this->assertSame('Test Org', SystemSetting::getValue('organization_name'));
        $this->assertSame('30', SystemSetting::getValue('default_factory_expiry_days'));
        $this->assertSame('180', SystemSetting::getValue('default_retail_expiry_days'));
    }
}
