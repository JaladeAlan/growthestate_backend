<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Referral rewards
    |--------------------------------------------------------------------------
    */

    // Kobo credited to the referrer's rewards wallet when their referred user
    // completes their first purchase.
    'referral_cashback_kobo' => (int) env('REWARD_REFERRAL_CASHBACK_KOBO', 5000),

    // Percentage discount given to the referred user on their next purchase
    // (stored as a ReferralReward row of type 'discount').
    'referral_discount_percent' => (int) env('REWARD_REFERRAL_DISCOUNT_PERCENT', 10),

    /*
    |--------------------------------------------------------------------------
    | First-purchase discount
    |--------------------------------------------------------------------------
    | Applied automatically on a user's very first purchase when use_rewards=1.
    | Takes priority over a referral discount reward. Set to 0 to disable.
    */
    'first_purchase_discount_percent' => (int) env('REWARD_FIRST_PURCHASE_DISCOUNT_PERCENT', 0),

];