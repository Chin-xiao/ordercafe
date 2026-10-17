<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('telegram_private_chat_id')->nullable()->unique();
            $table->timestamp('telegram_private_chat_activated_at')->nullable();
            $table->timestamp('telegram_last_seen_at')->nullable();
        });

        Schema::create('telegram_webhook_updates', function (Blueprint $table): void {
            $table->bigInteger('update_id')->primary();
            $table->string('status', 20)->default('processing');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_webhook_updates');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['telegram_private_chat_id']);
            $table->dropColumn([
                'telegram_private_chat_id',
                'telegram_private_chat_activated_at',
                'telegram_last_seen_at',
            ]);
        });
    }
};
