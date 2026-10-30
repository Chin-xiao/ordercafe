<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update Users table
        Schema::table('users', function (Blueprint $table) {
            // Check if 'role' column already exists before adding it
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('store_owner')->after('email');
            }

            // Check if 'store_id' column already exists before adding it
            if (!Schema::hasColumn('users', 'store_id')) {
                $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete()->after('id');
            }
        });

        // 2. Update Categories table
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // 3. Update Products table
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // 4. Update Order Sessions table
        Schema::table('order_sessions', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // 5. Update Orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Reverse the changes if needed
        Schema::table('orders', function (Blueprint $table) { $table->dropConstrainedForeignId('store_id'); });
        Schema::table('order_sessions', function (Blueprint $table) { $table->dropConstrainedForeignId('store_id'); });
        Schema::table('products', function (Blueprint $table) { $table->dropConstrainedForeignId('store_id'); });
        Schema::table('categories', function (Blueprint $table) { $table->dropConstrainedForeignId('store_id'); });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
