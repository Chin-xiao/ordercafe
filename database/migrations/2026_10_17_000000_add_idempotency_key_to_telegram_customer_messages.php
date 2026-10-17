<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_customer_messages', function (Blueprint $table): void {
            $table->string('idempotency_key', 191)->nullable();
            $table->unique(
                ['admin_id', 'idempotency_key'],
                'telegram_customer_messages_admin_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('telegram_customer_messages', function (Blueprint $table): void {
            $table->dropUnique('telegram_customer_messages_admin_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
