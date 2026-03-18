<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OPay Payment Service
 *
 * Handles order creation and verification with the OPay Cashier API.
 * Docs: https://documentation.opayweb.com/
 *
 * Config keys expected in config/services.php:
 *   services.opay.merchant_id
 *   services.opay.public_key
 *   services.opay.secret_key   (HMAC-SHA512 signing key)
 *   services.opay.base_url     (https://sandboxapi.opayweb.com  or  https://cashierapi.opayweb.com)
 */
class OpayService
{
    // ── API endpoints ──────────────────────────────────────────────────────────
    private const ENDPOINT_INITIALIZE = '/api/v3/cashier/initialize';
    private const ENDPOINT_QUERY      = '/api/v3/cashier/query';

    // ── Public interface ───────────────────────────────────────────────────────

    /**
     * Create an OPay payment order.
     *
     * @param  string  $email        Customer email
     * @param  string  $reference    Unique order reference
     * @param  int     $amountKobo   Amount in kobo (will be converted to NGN)
     * @param  string  $callbackUrl  URL OPay redirects to after payment
     * @param  string  $name         Customer name
     * @return array   Raw OPay API response body
     *
     * @throws \RuntimeException on HTTP failure or non-zero OPay error code
     */
    public static function initialize(
        string $email,
        string $reference,
        int    $amountKobo,
        string $callbackUrl,
        string $name = ''
    ): array {
        $amountNgn = $amountKobo / 100;          // OPay expects Naira, not kobo
        $merchantId = config('services.opay.merchant_id');

        $payload = [
            'reference'   => $reference,
            'mchShortName'=> config('app.name'),
            'productName' => 'Wallet Deposit',
            'productDesc' => 'Wallet top-up via OPay',
            'currency'    => 'NGN',
            'amount'      => number_format($amountNgn, 2, '.', ''),  // e.g. "5000.00"
            'returnUrl'   => $callbackUrl,
            'callbackUrl' => $callbackUrl,
            'expireAt'    => 30,       // minutes until order expires
            'userInfo'    => [
                'userEmail' => $email,
                'userName'  => $name ?: $email,
            ],
            'payMethods'  => [['payMethod' => 'BankCard'], ['payMethod' => 'Wallet']],
        ];

        $signature = static::buildSignature($payload);

        $response = Http::timeout(20)
            ->withHeaders([
                'MerchantId'   => $merchantId,
                'Authorization' => 'Bearer ' . config('services.opay.public_key'),
                'Signature'    => $signature,
                'Content-Type' => 'application/json',
            ])
            ->post(static::baseUrl(self::ENDPOINT_INITIALIZE), $payload);

        static::assertSuccessful($response, 'OPay initialize');

        $body = $response->json();

        if (($body['code'] ?? null) !== '00000') {
            $msg = $body['message'] ?? 'Unknown OPay error';
            Log::error('OPay initialize failed', ['reference' => $reference, 'body' => $body]);
            throw new \RuntimeException("OPay initialization failed: {$msg}");
        }

        Log::info('OPay order created', [
            'reference'   => $reference,
            'amount_ngn'  => $amountNgn,
            'cashier_url' => $body['data']['cashierUrl'] ?? null,
        ]);

        return $body;
    }

    /**
     * Verify an OPay payment order by merchant reference.
     *
     * @param  string $reference  The same reference used at initialization
     * @return array  OPay response body
     *
     * @throws \RuntimeException on HTTP failure or non-zero OPay error code
     */
    public static function verify(string $reference): array
    {
        $merchantId = config('services.opay.merchant_id');

        $payload = [
            'reference'  => $reference,
            'orderNo'    => '',          // optional; we use merchant reference
        ];

        $signature = static::buildSignature($payload);

        $response = Http::timeout(20)
            ->withHeaders([
                'MerchantId'    => $merchantId,
                'Authorization' => 'Bearer ' . config('services.opay.public_key'),
                'Signature'     => $signature,
                'Content-Type'  => 'application/json',
            ])
            ->post(static::baseUrl(self::ENDPOINT_QUERY), $payload);

        static::assertSuccessful($response, 'OPay verify');

        $body = $response->json();

        if (($body['code'] ?? null) !== '00000') {
            $msg = $body['message'] ?? 'Unknown OPay error';
            Log::warning('OPay verify non-zero code', ['reference' => $reference, 'body' => $body]);
            throw new \RuntimeException("OPay verification failed: {$msg}");
        }

        return $body;
    }

    /**
     * Verify the HMAC-SHA512 signature on an incoming OPay webhook.
     *
     * OPay sends the signature in the HTTP header: "Signature"
     * The signature covers the raw JSON request body.
     */
    public static function verifyWebhookSignature(string $rawBody, string $headerSignature): bool
    {
        $computed = hash_hmac('sha512', $rawBody, config('services.opay.secret_key'));
        return hash_equals($computed, $headerSignature);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Build the HMAC-SHA512 request signature.
     *
     * OPay signs the JSON-encoded payload body with the merchant secret key.
     */
    private static function buildSignature(array $payload): string
    {
        return hash_hmac('sha512', json_encode($payload), config('services.opay.secret_key'));
    }

    private static function baseUrl(string $endpoint): string
    {
        return rtrim(config('services.opay.base_url', 'https://sandboxapi.opayweb.com'), '/') . $endpoint;
    }

    private static function assertSuccessful(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->failed()) {
            Log::error("{$context} HTTP error", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("{$context} request failed (HTTP {$response->status()}).");
        }
    }
}