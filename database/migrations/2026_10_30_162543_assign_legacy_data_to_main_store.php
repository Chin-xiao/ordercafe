<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Assign all legacy/pre-multi-tenancy records to the main store (ID 1).
 *
 * This ensures that existing data created before multi-tenancy is properly
 * owned by the original/primary store, preventing it from leaking into
 * newly created stores.
 */
return new class extends Migration
{
    /**
     * The main store ID that should own all legacy data.
     */
    private const MAIN_STORE_ID = 1;

    public function up(): void
    {
        // Only update if the main store actually exists
        $mainStoreExists = DB::table('stores')->where('id', self::MAIN_STORE_ID)->exists();

        if (! $mainStoreExists) {
            // Create the main store if it doesn't exist yet
            DB::table('stores')->insert([
                'id'         => self::MAIN_STORE_ID,
                'name'       => 'Main Store',
                'slug'       => 'main-store',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Assign all orphaned records to the main store
        DB::table('order_sessions')->whereNull('store_id')->update(['store_id' => self::MAIN_STORE_ID]);
        DB::table('products')->whereNull('store_id')->update(['store_id' => self::MAIN_STORE_ID]);
        DB::table('orders')->whereNull('store_id')->update(['store_id' => self::MAIN_STORE_ID]);
        DB::table('categories')->whereNull('store_id')->update(['store_id' => self::MAIN_STORE_ID]);

        // Also assign any orphaned users (admins without a store) to the main store
        DB::table('users')
            ->whereNull('store_id')
            ->where('role', '!=', 'store_owner') // Don't touch store_owner accounts
            ->update(['store_id' => self::MAIN_STORE_ID]);
    }

    public function down(): void
    {
        // Revert: set store_id back to NULL for records assigned to main store
        // (Only safe if no new records were created for store 1 after migration)
        DB::table('order_sessions')->where('store_id', self::MAIN_STORE_ID)->update(['store_id' => null]);
        DB::table('products')->where('store_id', self::MAIN_STORE_ID)->update(['store_id' => null]);
        DB::table('orders')->where('store_id', self::MAIN_STORE_ID)->update(['store_id' => null]);
        DB::table('categories')->where('store_id', self::MAIN_STORE_ID)->update(['store_id' => null]);
    }
};
