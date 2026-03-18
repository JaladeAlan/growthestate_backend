<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;

/**
 * OPay Cashier Payment Service
 *
 * Staging:    https://testapi.opaycheckout.com
 * Production: https://liveapi.opaycheckout.com
 *
 * .env:
 *   OPAY_MERCHANT_ID=
 *   OPAY_PUBLIC_KEY=
 *   OPAY_SECRET_KEY=
 *   OPAY_BASE_URL=https://testapi.opaycheckout.com
 */
class OpayService
{
    private const ENDPOINT_CREATE = '/api/v1/international/cashier/create';
    private const ENDPOINT_STATUS = '/api/v1/international/cashier/query';

    /**
     * Create an OPay Cashier payment order.
     * Returns the full response body; caller reads $body['data']['cashierUrl'].
     */
    public static function initialize(
        string $email,
        string $reference,
        int    $amountKobo,
        string $returnUrl,
        string $name = ''
    ): array {
        // OPay expects amount.total as a number (int or float), not a string.
        // Their official sample uses 400 (integer). We send as float e.g. 1000.00
        $amountNaira = round($amountKobo / 100, 2);

        $payload = [
            'country'     => 'NG',
            'reference'   => $reference,
            'amount'      => [
                'total'    => $amountNaira,   // numeric, not string
                'currency' => 'NGN',
            ],
            'returnUrl'   => $returnUrl,
            'callbackUrl' => $returnUrl,
            'cancelUrl'   => $returnUrl,
            'expireAt'    => 30,
            'userInfo'    => [
                'userName'  => $name ?: $email,
                'userEmail' => $email,
            ],
            'product'     => [
                'name'        => 'Wallet Deposit',
                'description' => 'Growth Estate wallet top-up',
            ],
        ];

        return static::request(self::ENDPOINT_CREATE, $payload, 'OPay create');
    }

    /**
     * Query payment status by merchant reference.
     * Possible data.status: INITIAL | PENDING | SUCCESS | FAIL | CLOSE
     */
    public static function verify(string $reference): array
    {
        $payload = ['reference' => $reference, 'country' => 'NG'];

        return static::request(self::ENDPOINT_STATUS, $payload, 'OPay verify');
    }

    /**
     * Verify the HMAC-SHA512 signature on an incoming OPay webhook.
     * OPay sends:  Authorization: Bearer {HMAC-SHA512 of raw JSON body}
     */
    public static function verifyWebhookSignature(string $rawBody, string $headerSignature): bool
    {
        $computed = hash_hmac('sha512', $rawBody, config('services.opay.secret_key'));
        return hash_equals($computed, $headerSignature);
    }

    // ── Core request helper ───────────────────────────────────────────────────

    /**
     * Build, sign, and fire a cURL request — bypasses Guzzle entirely so there
     * is zero risk of double-encoding or Content-Type conflicts.
     *
     * OPay auth:
     *   Authorization: Bearer {HMAC-SHA512 of the exact JSON string being sent}
     *   MerchantId: {merchant_id}
     */
    private static function request(string $endpoint, array $payload, string $context): array
    {
        // Encode once — this exact string is both sent as the body and signed.
        $body      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $body, config('services.opay.secret_key'));
        $url       = rtrim(config('services.opay.base_url', 'https://testapi.opaycheckout.com'), '/')
                     . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $signature,
                'MerchantId: '           . config('services.opay.merchant_id'),
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error("{$context} cURL error", ['error' => $curlError, 'url' => $url]);
            throw new \RuntimeException("{$context} network error: {$curlError}");
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            Log::error("{$context} HTTP error", [
                'status' => $httpStatus,
                'body'   => $responseBody,
                'url'    => $url,
            ]);
            throw new \RuntimeException("{$context} failed (HTTP {$httpStatus}): {$responseBody}");
        }

        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("{$context} invalid JSON response", ['body' => $responseBody]);
            throw new \RuntimeException("{$context} returned non-JSON: {$responseBody}");
        }

        if (($decoded['code'] ?? null) !== '00000') {
            Log::error("{$context} failed", [
                'code'    => $decoded['code']    ?? null,
                'message' => $decoded['message'] ?? null,
                'body'    => $decoded,
            ]);
            throw new \RuntimeException(
                "{$context} failed: " . ($decoded['message'] ?? 'unknown error')
            );
        }

        Log::info("{$context} success", [
            'code'         => $decoded['code'],
            'cashier_url'  => $decoded['data']['cashierUrl'] ?? null,
            'order_no'     => $decoded['data']['orderNo']    ?? null,
        ]);

        return $decoded;
    }
}