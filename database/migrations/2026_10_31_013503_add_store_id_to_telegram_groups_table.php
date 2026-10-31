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
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete()->after('id');
        });

        // Assign all existing telegram groups to the main store
        DB::table('telegram_groups')
            ->whereNull('store_id')
            ->update(['store_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('telegram_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
