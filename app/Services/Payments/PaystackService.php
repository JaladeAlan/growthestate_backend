<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

class PaystackService
{
    public static function initialize(string $email, int $amountKobo, string $reference, string $callbackUrl)
    {
        return Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transaction/initialize', [
                'email'        => $email,
                'amount'       => $amountKobo,
                'reference'    => $reference,
                'callback_url' => $callbackUrl,
            ]);
    }
}
