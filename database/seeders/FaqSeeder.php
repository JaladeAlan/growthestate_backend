<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Faq;

class FaqSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Faq::insert([
            ['category'=>'account',    'question'=>'How do I reset my transaction PIN?',          'answer'=>'Go to Settings → Transaction PIN → Reset PIN. Enter your email to receive a reset code.',              'sort_order'=>1,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['category'=>'account',    'question'=>'How do I complete KYC verification?',          'answer'=>'Go to Settings → KYC. Complete all 6 steps including liveness check. Approval takes 24–48 hours.',   'sort_order'=>2,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['category'=>'payment',    'question'=>'How do I fund my wallet?',                     'answer'=>'Go to Wallet → Deposit. Enter amount and pay via Paystack using card, bank transfer, or USSD.',       'sort_order'=>3,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['category'=>'payment',    'question'=>'How long do withdrawals take?',                'answer'=>'Withdrawals are processed within 1–3 business days to your verified bank account.',                    'sort_order'=>4,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['category'=>'investment', 'question'=>'What is a land unit?',                         'answer'=>'A unit is a fixed fraction of a land plot. You can buy as few as 1 unit and earn returns as the land appreciates.', 'sort_order'=>5,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['category'=>'investment', 'question'=>'Can I sell my units back?',                    'answer'=>'Yes. Go to Portfolio → select a land → Sell Units. Proceeds are credited to your wallet.',           'sort_order'=>6,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['category'=>'kyc',        'question'=>'My KYC was rejected. What should I do?',       'answer'=>'Check your rejection reason in Settings → KYC. Re-upload clearer documents and resubmit.',          'sort_order'=>7,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['category'=>'other',      'question'=>'How do I contact a human agent?',              'answer'=>'Use the support chat to escalate, or submit a ticket and our team will respond within 24 hours.',     'sort_order'=>8,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }
}
