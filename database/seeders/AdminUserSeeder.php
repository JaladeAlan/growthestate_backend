<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'jaladealan007@gmail.com'],
            [
                'name' => 'Admin',
                'uid'                => (string) Str::uuid(),
                'password' => Hash::make('Securepass123!'),
                'is_admin' => 1,
                'email_verified_at' => Carbon::now(),
                'balance_kobo' => 10000000000,
            ]
        );
    }
}
