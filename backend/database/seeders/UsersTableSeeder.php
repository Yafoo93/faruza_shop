<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;


class UsersTableSeeder extends Seeder
{
    public function run()
    {
        User::updateOrCreate(
            ['email' => 'admin@shop.com'],
            [
                'name' => 'Shop Owner',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'cashier@shop.com'],
            [
                'name' => 'Shop Cashier',
                'password' => Hash::make('cashier123'),
                'role' => 'cashier',
            ]
        );
    }
}