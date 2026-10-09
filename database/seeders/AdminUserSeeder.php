<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('services.admin.password');

        if (!$password) {
            throw new \RuntimeException('Set ADMIN_PASSWORD before running the admin user seeder.');
        }

        User::updateOrCreate(
            ['email' => config('services.admin.email')],
            [
                'name' => 'System Admin',
                'password' => Hash::make($password),
                'is_admin' => true,
                'is_active' => true,
            ]
        );
    }
}
