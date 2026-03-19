<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Log;

class OpayService
{
    private const ENDPOINT_CREATE = '/api/v1/international/cashier/create';
    private const ENDPOINT_STATUS = '/api/v1/international/cashier/query';

    public static function initialize(
        string $email,
        string $reference,
        int $amountKobo,
        string $returnUrl,
        string $name = ''
    ): array {
        $amountNaira = (int) ($amountKobo / 100);

        $payload = [
            "country"   => "NG",
            "reference" => $reference,
            "amount"    => [
                "total"    => $amountNaira,
                "currency" => "NGN",
            ],
            "returnUrl"   => $returnUrl,
            "cancelUrl"   => $returnUrl,
            "callbackUrl" => route('opay.webhook'),
            "expireAt"    => 30,
            "userInfo" => [
                "userName"  => $name ?: $email,
                "userEmail" => $email,
            ],
            "product" => [
                "name"        => "Wallet Deposit",
                "description" => "Sproutvest wallet top-up",
            ],
        ];

        $body      = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $body, config('services.opay.secret_key'));
        $publicKey = config('services.opay.public_key');
        $url       = rtrim(config('services.opay.base_url', 'https://testapi.opaycheckout.com'), '/') . self::ENDPOINT_CREATE;

        Log::info('OPay create request', [
            'url'         => $url,
            'body'        => $body,
            'signature'   => $signature,
            'merchant_id' => config('services.opay.merchant_id'),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $publicKey,  // ← public key, not HMAC
                'MerchantId: '          . config('services.opay.merchant_id'),
                'Signature: '           . $signature,   // ← HMAC goes here
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Log::error("OPay create cURL error", ['error' => $curlError]);
            throw new \RuntimeException("OPay create network error: {$curlError}");
        }

        Log::info('OPay create response', [
            'status' => $httpStatus,
            'body'   => $responseBody,
        ]);

        $decoded = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("OPay create invalid JSON", ['body' => $responseBody]);
            throw new \RuntimeException("OPay create returned non-JSON: {$responseBody}");
        }

        if (($decoded['code'] ?? null) !== '00000') {
            Log::error("OPay create failed", ['body' => $decoded]);
            throw new \RuntimeException("OPay create failed: " . ($decoded['message'] ?? 'unknown error'));
        }

        Log::info("OPay create success", [
            'code'        => $decoded['code'],
            'cashier_url' => $decoded['data']['cashierUrl'] ?? null,
            'order_no'    => $decoded['data']['orderNo'] ?? null,
        ]);

        return $decoded;
    }

    public static function verifyWebhookSignature(string $rawBody, string $headerSignature): bool
    {
        $computed = hash_hmac('sha512', $rawBody, config('services.opay.secret_key'));
        Log::info('OPay signature verification', [
            'header_signature'   => $headerSignature,
            'computed_signature' => $computed,
            'match'              => hash_equals($computed, $headerSignature),
        ]);
        return hash_equals($computed, $headerSignature);
    }
}