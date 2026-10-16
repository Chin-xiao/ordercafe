<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('sugar_percent')->nullable();
        });

        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('sugar_percent');
        });
    }
};
