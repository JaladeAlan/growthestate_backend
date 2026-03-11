<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Referral Rewards Configuration
    |--------------------------------------------------------------------------
    |
    | All reward amounts are stored in KOBO (1 Naira = 100 Kobo).
    | Change these values here rather than in controller/service code.
    |
    */

    // Amount credited to the referrer's rewards wallet when their referee
    // makes their first purchase. Default: ₦50 = 5,000 kobo.
    'referral_cashback_kobo' => (int) env('REWARD_REFERRAL_CASHBACK_KOBO', 5000),

    // One-time purchase discount percentage applied to the referred user's
    // first purchase. Default: 10%.
    'referral_discount_percent' => (int) env('REWARD_REFERRAL_DISCOUNT_PERCENT', 10),

];