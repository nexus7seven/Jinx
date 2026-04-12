<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'alex@clearmycredit.co.uk'],
            [
                'name' => 'Alex',
                'password' => Hash::make('Redr00k2.'),
            ]
        );
    }
}