<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;

class OpayService
{
    private const ENDPOINT_CREATE = '/api/v1/international/cashier/create';
    private const ENDPOINT_STATUS = '/api/v1/international/cashier/status';

    public static function initialize(
        string $email,
        string $reference,
        int $amountKobo,        // Receives kobo, converts to naira internally
        string $returnUrl,
        string $name = ''
    ): array {
        // $amountNaira = (int) ($amountKobo / 100);

        $payload = [
            'country'   => 'NG',
            'reference' => $reference,
            'amount'    => [
                'total'    => $amountKobo,
                'currency' => 'NGN',
            ],
            'returnUrl'   => $returnUrl,
            'cancelUrl'   => $returnUrl,
            'callbackUrl' => route('opay.webhook'),
            'expireAt'    => 30,
            'userInfo'    => [
                'userName'  => $name ?: $email,
                'userEmail' => $email,
            ],
            'product' => [
                'name'        => 'Wallet Deposit',
                'description' => 'REU.ng wallet top-up',
            ],
        ];

        return self::post(self::ENDPOINT_CREATE, $payload);
    }

    public static function status(string $reference): array
    {
        $payload = [
            'country'   => 'NG',
            'reference' => $reference,
        ];

        return self::post(self::ENDPOINT_STATUS, $payload);
    }

    private static function post(string $endpoint, array $payload): array
    {
        $body    = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $baseUrl = rtrim(config('services.opay.base_url', 'https://testapi.opaycheckout.com'), '/');
        $url     = $baseUrl . $endpoint;

        Log::info('OPay API request', [
            'url'  => $url,
            'body' => $body,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . config('services.opay.public_key'),
                'MerchantId: '           . config('services.opay.merchant_id'),
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error('OPay cURL error', ['endpoint' => $endpoint, 'error' => $curlError]);
            throw new \RuntimeException("OPay network error: {$curlError}");
        }

        Log::info('OPay API response', ['status' => $httpStatus, 'body' => $responseBody]);

        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('OPay non-JSON response', ['body' => $responseBody]);
            throw new \RuntimeException("OPay returned non-JSON: {$responseBody}");
        }

        if (($decoded['code'] ?? null) !== '00000') {
            Log::error('OPay request failed', ['endpoint' => $endpoint, 'response' => $decoded]);
            throw new \RuntimeException('OPay error: ' . ($decoded['message'] ?? 'unknown'));
        }

        return $decoded;
    }

    /**
     * Webhook signature: OPay sends the HMAC of the raw request body.
     * Always pass $request->getContent() — never the decoded array.
     */
    public static function verifyWebhookSignature(string $rawBody, string $headerSignature): bool
    {
        $computed = hash_hmac('sha512', $rawBody, config('services.opay.secret_key'));

        Log::info('OPay webhook signature check', [
            'match' => hash_equals($computed, strtolower($headerSignature)),
        ]);

        return hash_equals($computed, strtolower($headerSignature));
    }
}