<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach (['Drink', 'Snacks'] as $index => $name) {
            $exists = DB::table('categories')
                ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                ->exists();

            if (!$exists) {
                DB::table('categories')->insert([
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep seeded categories on rollback so existing product assignments are not lost.
    }
};
