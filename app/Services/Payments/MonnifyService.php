<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MonnifyService
{
    // Monnify access tokens are valid for 1 hour; cache with a safety
    // margin so we never hand out a token that expires mid-request.
    private const TOKEN_TTL_SECONDS = 3000; // 50 minutes

    protected static function authToken(): string
    {
        return Cache::remember('monnify_access_token', self::TOKEN_TTL_SECONDS, function () {
            $credentials = base64_encode(
                config('services.monnify.api_key') . ':' .
                config('services.monnify.secret_key')
            );

            $response = Http::withHeaders([
                'Authorization' => "Basic {$credentials}",
            ])->post(config('services.monnify.base_url') . '/api/v1/auth/login');

            $token = $response['responseBody']['accessToken'] ?? null;

            if (! $response->successful() || ! $token) {
                throw new \RuntimeException('Monnify auth failed: ' . $response->body());
            }

            return $token;
        });
    }

    public static function initialize(
        string $email,
        string $reference,
        int $amountKobo,
        string $callbackUrl,
        string $name
    ) {
        $token = self::authToken();

        return Http::withToken($token)
            ->post(config('services.monnify.base_url') . '/api/v1/merchant/transactions/init-transaction', [
                'amount'        => $amountKobo / 100,
                'customerName'  => $name,
                'customerEmail' => $email,
                'paymentReference' => $reference,
                'paymentDescription' => 'Wallet Deposit',
                'currencyCode'  => 'NGN',
                'contractCode'  => config('services.monnify.contract_code'),
                'redirectUrl'   => $callbackUrl,
                'paymentMethods'=> ['CARD', 'ACCOUNT_TRANSFER'],
            ]);
    }
}
