<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\KycVerification;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class NewUserSeeder extends Seeder
{
    public function run(): void
    {
        // ── Regular test user ─────────────────────────────────────────────
        $user = User::updateOrCreate(
            ['email' => 'sproutvest@gmail.com'],
            [
                'name'               => 'Sprout Veest',
                'password'           => Hash::make('Securepass123!'),
                'email_verified_at'  => now(),
                'balance_kobo'       => 500_000_00,   // ₦500,000
                'rewards_balance_kobo' => 10_000_00,  // ₦10,000
                'is_admin'           => false,
                'is_suspended'       => false,
                'bank_name'          => 'First Bank',
                'bank_code'          => '011',
                'account_number'     => '3012345678',
                'account_name'       => 'Sprout Veest',
                'recipient_code'     => 'RCP_recipient123',
                'bank_verified'      => true,
            ]
        );

        $this->command->info('✓ Test users seeded');
        $this->command->info('  Regular → sproutvest@gmaial.com / Securepass123!');
    }
}