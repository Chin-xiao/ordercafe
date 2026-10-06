<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@ordercafe.com'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password123'),
                'is_admin' => true,
            ]
        );
    }
}
