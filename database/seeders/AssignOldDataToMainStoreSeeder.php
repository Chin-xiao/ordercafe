<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AssignOldDataToMainStoreSeeder extends Seeder
{
    public function run(): void
    {
        // IMPORTANT: Back up your database before running this just in case.

        DB::transaction(function () {
            // 1. Create the Main Store
            $mainStore = Store::firstOrCreate([
                'slug' => 'main-store'
            ], [
                'name' => 'Main Cafe (Legacy Data)',
                'is_active' => true,
            ]);

            $storeId = $mainStore->id;

            // 2. Assign existing users (Super Admin can remain null, others get assigned)
            // Assuming non-super admins are store owners
            User::where('role', '!=', 'super_admin')
                ->whereNull('store_id')
                ->update(['store_id' => $storeId]);

            // 3. Assign all existing data to this store
            Category::whereNull('store_id')->update(['store_id' => $storeId]);
            Product::whereNull('store_id')->update(['store_id' => $storeId]);
            OrderSession::whereNull('store_id')->update(['store_id' => $storeId]);
            Order::whereNull('store_id')->update(['store_id' => $storeId]);

            $this->command->info('All existing data has been assigned to Store ID: ' . $storeId);
        });
    }
}
