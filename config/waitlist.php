<?php

// Controls how much referrals move people in the queue.
//
//   referral_boost  — how many spots a new referred entrant jumps ahead
//   referrer_boost  — how many spots the referrer gains per successful referral
 
return [
    'referral_boost' => env('WAITLIST_REFERRAL_BOOST', 5),
    'referrer_boost' => env('WAITLIST_REFERRER_BOOST', 3),
];

?>