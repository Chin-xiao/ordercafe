<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_groups', function (Blueprint $table) {
            $table->string('group_name')->nullable();
            $table->string('verify_token')->nullable()->unique();
            $table->boolean('is_verified')->default(false);
            $table->bigInteger('telegram_chat_id')->nullable()->change();
        });

        DB::table('telegram_groups')
            ->whereNotNull('telegram_chat_id')
            ->update(['is_verified' => true]);
    }

    public function down(): void
    {
        if (DB::table('telegram_groups')->whereNull('telegram_chat_id')->exists()) {
            throw new RuntimeException('Cannot roll back Telegram group verification while an unlinked group exists.');
        }

        Schema::table('telegram_groups', function (Blueprint $table) {
            $table->dropUnique(['verify_token']);
            $table->dropColumn(['group_name', 'verify_token', 'is_verified']);
            $table->bigInteger('telegram_chat_id')->nullable(false)->change();
        });
    }
};
