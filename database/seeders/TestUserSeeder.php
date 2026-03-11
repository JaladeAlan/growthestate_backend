<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\KycVerification;
use App\Models\Transaction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    public function run(): void
    {
        // ── Regular test user ─────────────────────────────────────────────
        $user = User::updateOrCreate(
            ['email' => 'ucheoma@gmail.com'],
            [
                'name'               => 'Omas Vee',
                'password'           => Hash::make('Securepass123!'),
                'email_verified_at'  => now(),
                'balance_kobo'       => 500_000_00,   // ₦500,000
                'rewards_balance_kobo' => 10_000_00,  // ₦10,000
                'is_admin'           => false,
                'is_suspended'       => false,
                'bank_name'          => 'First Bank',
                'bank_code'          => '011',
                'account_number'     => '3012345678',
                'account_name'       => 'Omas Vee',
                'recipient_code'     => 'RCP_testrecipient123',
                'bank_verified'      => true,
            ]
        );

        // KYC — approved
        KycVerification::updateOrCreate(
            ['user_id' => $user->id],
            [
                'full_name'        => 'Omas Vee',
                'date_of_birth'    => '1995-06-15',
                'phone_number'     => '08012345678',
                'address'          => '12 Lekki Phase 1',
                'city'             => 'Lagos',
                'state'            => 'Lagos',
                'country'          => 'Nigeria',
                'id_type'          => 'nin',
                'id_number'        => '12345678901',
                'id_front_path'    => 'seeder/placeholder_id_front.jpg',
                'id_back_path'     => 'seeder/placeholder_id_back.jpg',
                'selfie_path'      => 'seeder/placeholder_selfie.jpg',
                'status'           => 'approved',
                'verified_at'      => now(),
                'rejection_reason' => null,
            ]
        );


        $this->command->info('✓ Test users seeded');
        $this->command->info('  Regular → ucheoma@gmail.com / Securepass123!');
    }
}