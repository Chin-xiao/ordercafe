<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sessions', function (Blueprint $table) {
            $table->timestamp('scheduled_start_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('announcement_message')->nullable();
        });

        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->bigInteger('telegram_chat_id')->nullable();
            $table->string('status', 20)->default('sent');
            $table->text('error_message')->nullable();
            $table->index(['order_session_id', 'message_type', 'status'], 'telegram_messages_session_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->dropIndex('telegram_messages_session_status_index');
            $table->dropColumn(['telegram_chat_id', 'status', 'error_message']);
        });

        Schema::table('order_sessions', function (Blueprint $table) {
            $table->dropColumn(['scheduled_start_at', 'duration_minutes', 'announcement_message']);
        });
    }
};
