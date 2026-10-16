<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_status_submitted_at_index ON orders (status, submitted_at DESC)'
            );
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_status_index');

            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_status_index');
            $table->index(['status', 'submitted_at'], 'orders_status_submitted_at_index');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_status_index ON orders (status)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_status_submitted_at_index');

            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_status_submitted_at_index');
            $table->index('status', 'orders_status_index');
        });
    }
};
