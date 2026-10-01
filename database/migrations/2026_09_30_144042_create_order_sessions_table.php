<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->string('title');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('status', 'sessions_status_index');
            $table->index('expires_at', 'sessions_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sessions');
    }
};
