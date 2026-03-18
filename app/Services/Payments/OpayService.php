<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OPay Cashier Payment Service
 *
 * Docs: https://documentation.opaycheckout.com/cashier-create
 *
 * Config keys (config/services.php → services.opay.*):
 *   merchant_id — numeric merchant ID,  e.g. 2566260685
 *   public_key  — OPAYPUB...  (used as Bearer token for status/query APIs)
 *   secret_key  — OPAYPRV...  (used to sign the create-payment payload)
 *   base_url    — https://testapi.opaycheckout.com   (staging)
 *                 https://liveapi.opaycheckout.com   (production)
 *
 * .env keys:
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
     *
     * Authentication for CREATE:
     *   Authorization: Bearer {HMAC-SHA512 of raw JSON payload signed with secret_key}
     *   MerchantId: {merchant_id}
     *
     * On success, redirect the user to $body['data']['cashierUrl'].
     *
     * @param  int  $amountKobo  Amount in kobo — converted to Naira internally
     * @return array             Full OPay JSON response
     * @throws \RuntimeException on network failure or non-00000 OPay code
     */
    public static function initialize(
        string $email,
        string $reference,
        int    $amountKobo,
        string $returnUrl,
        string $name = ''
    ): array {
        $amountNaira = number_format($amountKobo / 100, 2, '.', '');

        $payload = [
            'country'     => 'NG',
            'reference'   => $reference,
            'amount'      => [
                'total'    => $amountNaira,
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

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature   = hash_hmac('sha512', $jsonPayload, config('services.opay.secret_key'));

        $response = Http::timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $signature,
                'MerchantId'    => config('services.opay.merchant_id'),
                'Content-Type'  => 'application/json',
            ])
            ->withBody($jsonPayload, 'application/json')
            ->post(static::url(self::ENDPOINT_CREATE));

        static::assertSuccessful($response, 'OPay create');

        $body = $response->json();

        if (($body['code'] ?? null) !== '00000') {
            Log::error('OPay create payment failed', [
                'reference' => $reference,
                'code'      => $body['code']    ?? null,
                'message'   => $body['message'] ?? null,
                'body'      => $body,
            ]);
            throw new \RuntimeException(
                'OPay payment creation failed: ' . ($body['message'] ?? 'unknown error')
            );
        }

        Log::info('OPay cashier order created', [
            'reference'   => $reference,
            'amount_ngn'  => $amountNaira,
            'cashier_url' => $body['data']['cashierUrl'] ?? null,
        ]);

        return $body;
    }

    /**
     * Query payment status by merchant reference.
     *
     * Authentication for STATUS queries:
     *   Authorization: Bearer {HMAC-SHA512 of raw JSON payload signed with secret_key}
     *   MerchantId: {merchant_id}
     *
     * Possible data.status values: INITIAL | PENDING | SUCCESS | FAIL | CLOSE
     */
    public static function verify(string $reference): array
    {
        $payload     = ['reference' => $reference, 'country' => 'NG'];
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature   = hash_hmac('sha512', $jsonPayload, config('services.opay.secret_key'));

        $response = Http::timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $signature,
                'MerchantId'    => config('services.opay.merchant_id'),
                'Content-Type'  => 'application/json',
            ])
            ->withBody($jsonPayload, 'application/json')
            ->post(static::url(self::ENDPOINT_STATUS));

        static::assertSuccessful($response, 'OPay verify');

        $body = $response->json();

        if (($body['code'] ?? null) !== '00000') {
            throw new \RuntimeException(
                'OPay verification failed: ' . ($body['message'] ?? 'unknown error')
            );
        }

        return $body;
    }

    /**
     * Verify the HMAC-SHA512 signature on an incoming OPay webhook callback.
     *
     * OPay sends:  Authorization: Bearer {signature}
     * Signature = HMAC-SHA512(raw JSON body, secret_key)
     */
    public static function verifyWebhookSignature(string $rawBody, string $headerSignature): bool
    {
        $computed = hash_hmac('sha512', $rawBody, config('services.opay.secret_key'));
        return hash_equals($computed, $headerSignature);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function url(string $endpoint): string
    {
        return rtrim(
            config('services.opay.base_url', 'https://testapi.opaycheckout.com'),
            '/'
        ) . $endpoint;
    }

    private static function assertSuccessful(
        \Illuminate\Http\Client\Response $response,
        string $context
    ): void {
        if ($response->failed()) {
            Log::error("{$context} HTTP error", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException(
                "{$context} request failed (HTTP {$response->status()}): " . $response->body()
            );
        }
    }
}