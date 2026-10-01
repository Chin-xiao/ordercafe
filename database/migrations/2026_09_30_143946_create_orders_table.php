<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('order_session_id')->constrained('order_sessions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('status', 30)->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('order_session_id', 'orders_session_index');
            $table->index('user_id', 'orders_user_index');
            $table->index('status', 'orders_status_index');

            // Unique constraint: One user can have only one order per session
            $table->unique(['order_session_id', 'user_id'], 'orders_session_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
