<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // <--- Added for Admin registration
            $table->string('email')->unique(); // <--- Added for Admin login/registration
            $table->string('password'); // <--- Added for Admin authentication
            $table->bigInteger('telegram_id')->unique()->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('role', 30)->default('user');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
