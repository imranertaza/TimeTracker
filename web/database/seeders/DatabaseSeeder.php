<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@officetracker.test'],
            [
                'name' => 'Office Admin',
                'password' => 'Admin@12345',
                'role' => 'admin',
                'is_active' => true,
                'timezone' => config('tracker.timezone', 'Asia/Dhaka'),
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'staff@officetracker.test'],
            [
                'name' => 'Demo Staff',
                'password' => 'Staff@12345',
                'role' => 'staff',
                'is_active' => true,
                'timezone' => config('tracker.timezone', 'Asia/Dhaka'),
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'staff2@officetracker.test'],
            [
                'name' => 'Second Staff',
                'password' => 'Staff@12345',
                'role' => 'staff',
                'is_active' => true,
                'timezone' => config('tracker.timezone', 'Asia/Dhaka'),
            ],
        );
    }
}
