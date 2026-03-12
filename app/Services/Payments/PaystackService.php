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

    public static function getBanks(): array
    {
        $response = Http::withToken(config('services.paystack.secret_key'))
            ->get('https://api.paystack.co/bank', [
                'country'  => 'nigeria',
                'per_page' => 100,
                'use_cursor' => false,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Paystack bank list request failed: ' . $response->body());
        }

        return $response->json();
    }

    public static function resolveAccount(string $accountNumber, string $bankCode): array
    {
        $response = Http::withToken(config('services.paystack.secret_key'))
            ->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code'      => $bankCode,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Paystack account resolution failed: ' . $response->body());
        }

        return $response->json();
    }
}
