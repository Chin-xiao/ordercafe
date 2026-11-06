<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_a_store_with_default_categories(): void
    {
        $headers = $this->superAdminHeaders();

        $response = $this->postJson('/api/super-admin/stores', [
            'name' => 'Coffee Shop',
            'owner_name' => 'Store Owner',
            'owner_email' => 'owner@example.com',
            'owner_password' => 'password',
            'auto_seed_categories' => true,
        ], $headers)->assertCreated();

        $storeId = $response->json('data.id');

        $response->assertJsonPath('data.auto_seed_categories', true)
            ->assertJsonPath('data.categories.0.name', 'Drink')
            ->assertJsonPath('data.categories.0.is_active', true)
            ->assertJsonPath('data.categories.0.sort_order', 1)
            ->assertJsonPath('data.categories.1.name', 'Snacks')
            ->assertJsonPath('data.categories.1.is_active', true)
            ->assertJsonPath('data.categories.1.sort_order', 2);

        $this->assertDatabaseHas('categories', [
            'name' => 'Drink',
            'store_id' => $storeId,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('categories', [
            'name' => 'Snacks',
            'store_id' => $storeId,
            'is_active' => true,
            'sort_order' => 2,
        ]);
    }

    public function test_super_admin_can_create_a_store_without_default_categories(): void
    {
        $response = $this->postJson('/api/super-admin/stores', [
            'name' => 'Empty Shop',
            'owner_name' => 'Store Owner',
            'owner_email' => 'empty-owner@example.com',
            'owner_password' => 'password',
            'auto_seed_categories' => false,
        ], $this->superAdminHeaders())->assertCreated();

        $storeId = $response->json('data.id');

        $response->assertJsonPath('data.auto_seed_categories', false);
        $this->assertSame(0, Category::where('store_id', $storeId)->count());
    }

    public function test_super_admin_can_update_auto_seed_categories(): void
    {
        $store = Store::create([
            'name' => 'Existing Shop',
            'slug' => 'existing-shop',
            'auto_seed_categories' => true,
        ]);

        $this->patchJson('/api/super-admin/stores/'.$store->id, [
            'auto_seed_categories' => false,
        ], $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonPath('data.auto_seed_categories', false);

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'auto_seed_categories' => false,
        ]);
    }

    private function superAdminHeaders(): array
    {
        $superAdmin = User::factory()->create([
            'is_admin' => true,
            'role' => 'superadmin',
        ]);

        return [
            'Authorization' => 'Bearer '.$superAdmin->createToken('test-super-admin')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }
}
