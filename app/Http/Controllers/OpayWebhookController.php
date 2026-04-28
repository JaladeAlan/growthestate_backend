<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpayCheckoutController extends Controller
{
    private string $publicKey;
    private string $secretKey;
    private string $merchantId;
    private string $baseUrl;
    private string $country;
    private string $currency;

    private const FEE_PERCENT = 2;
    private const FEE_CAP     = 300000; // ₦3,000 cap in kobo

    public function __construct()
    {
        $this->publicKey  = config('services.opay.public_key');
        $this->secretKey  = config('services.opay.secret_key');
        $this->merchantId = config('services.opay.merchant_id');
        $this->baseUrl    = config('services.opay.sandbox')
            ? 'https://sandboxapi.opaycheckout.com'
            : 'https://api.opaycheckout.com';
        $this->country  = config('services.opay.country', 'NG');
        $this->currency = config('services.opay.currency', 'NGN');
    }

    // -------------------------------------------------------------------------
    // 1.  Initiate – create a Deposit record, redirect to OPay cashier
    // -------------------------------------------------------------------------

    /**
     * POST /deposit/opay
     *
     * Expected body (from the wallet frontend):
     *   { "amount": 100000, "gateway": "opay" }   ← amount in kobo
     */
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|integer|min:100000', // ₦1,000 minimum
        ]);

        /** @var User $user */
        $user = $request->user();

        $amountKobo = (int) $validated['amount'];
        $fee        = (int) min(round($amountKobo * self::FEE_PERCENT / 100), self::FEE_CAP);
        $totalKobo  = $amountKobo + $fee;
        $reference  = 'OPAY-' . strtoupper(Str::random(16));

        // Persist the deposit before redirecting so the webhook always finds it
        $deposit = Deposit::create([
            'user_id'         => $user->id,
            'reference'       => $reference,
            'amount_kobo'     => $amountKobo,
            'transaction_fee' => $fee,
            'total_kobo'      => $totalKobo,
            'status'          => Deposit::STATUS_PENDING,
            'gateway'         => 'opay',
        ]);

        $payload = [
            'country'     => $this->country,
            'reference'   => $reference,
            'amount'      => [
                'total'    => $totalKobo,
                'currency' => $this->currency,
            ],
            'returnUrl'   => route('opay.return'),
            'callbackUrl' => route('opay.webhook'),
            'cancelUrl'   => route('opay.cancel'),
            'expireAt'    => 30,
            'userInfo'    => [
                'userId'     => (string) $user->id,
                'userName'   => $user->name,
                'userEmail'  => $user->email,
                'userMobile' => $user->phone ?? '',
            ],
            'productList' => [[
                'productId'   => 'wallet-topup',
                'name'        => 'Wallet Top-up',
                'description' => 'Credit wallet balance',
                'price'       => $totalKobo,
                'quantity'    => 1,
                'imageUrl'    => '',
            ]],
        ];

        $response = $this->post('/api/v1/international/cashier/create', $payload);

        if (!$response || $response['code'] !== '00000') {
            $message = $response['message'] ?? 'Failed to initiate OPay checkout.';
            Log::error('OPay initiate failed', [
                'user_id'   => $user->id,
                'reference' => $reference,
                'response'  => $response,
            ]);

            // Clean up the pending deposit so the reference doesn't orphan
            $deposit->delete();

            return response()->json(['message' => $message], 502);
        }

        $cashierUrl = $response['data']['cashierUrl'] ?? null;

        if (!$cashierUrl) {
            Log::error('OPay: cashierUrl missing in response', $response);
            $deposit->delete();
            return response()->json(['message' => 'Could not retrieve payment URL. Please try again.'], 502);
        }

        return response()->json(['payment_url' => $cashierUrl]);
    }

    // -------------------------------------------------------------------------
    // 2.  Return URL – customer lands here after the cashier
    // -------------------------------------------------------------------------

    /**
     * GET /deposit/opay/return?reference=xxx&status=SUCCESS|FAILED
     */
    public function returnUrl(Request $request)
    {
        $reference = $request->query('reference');

        if (!$reference) {
            return redirect()->route('home')->with('error', 'Invalid payment return.');
        }

        // Always re-verify server-side
        $verified  = $this->queryPaymentStatus($reference);
        $payStatus = $verified['data']['status'] ?? 'FAILED';

        if ($payStatus === 'SUCCESS') {
            return redirect()->route('wallet')->with('success', 'Deposit successful! Your wallet has been credited.');
        }

        return redirect()->route('wallet')->with('error', 'Payment was not completed. Status: ' . $payStatus);
    }

    // -------------------------------------------------------------------------
    // 3.  Cancel URL
    // -------------------------------------------------------------------------

    /**
     * GET /deposit/opay/cancel
     */
    public function cancel(Request $request)
    {
        $reference = $request->query('reference');

        if ($reference) {
            Deposit::where('reference', $reference)
                ->where('gateway', 'opay')
                ->pending()
                ->update(['status' => Deposit::STATUS_FAILED]);
        }

        return redirect()->route('wallet')->with('info', 'Payment was cancelled.');
    }

    // -------------------------------------------------------------------------
    // 4.  Webhook – OPay POSTs payment updates here (no CSRF, no auth)
    // -------------------------------------------------------------------------

    /**
     * POST /webhook/opay
     */
    public function webhook(Request $request)
    {
        $body      = $request->getContent();
        $data      = json_decode($body, true);
        $signature = $data['sha512'] ?? '';

        if (!$this->verifyWebhookSignature($data['payload'] ?? [], $signature)) {
            Log::warning('OPay webhook: invalid signature', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload   = $data['payload'];
        $reference = $payload['reference'] ?? null;
        $status    = strtoupper($payload['status'] ?? '');

        Log::info('OPay webhook received', ['reference' => $reference, 'status' => $status]);

        if (!$reference) {
            return response()->json(['message' => 'Missing reference'], 400);
        }

        $deposit = Deposit::where('reference', $reference)
            ->where('gateway', 'opay')
            ->first();

        if (!$deposit) {
            Log::warning('OPay webhook: deposit not found', ['reference' => $reference]);
            // Acknowledge to stop retries — not our reference
            return response()->json(['message' => 'OK'], 200);
        }

        // Idempotency guard (mirrors Paystack/Monnify pattern)
        if ($deposit->processed_at !== null) {
            return response()->json(['message' => 'Already processed'], 200);
        }

        match ($status) {
            'SUCCESS' => $this->handleSuccess($deposit, $payload),
            'FAILED'  => $this->handleFailed($deposit, $payload),
            default   => Log::info('OPay webhook: unhandled status', ['status' => $status]),
        };

        return response()->json(['message' => 'OK'], 200);
    }

    // -------------------------------------------------------------------------
    // 5.  Status query – optional admin/debug route
    // -------------------------------------------------------------------------

    public function status(string $reference)
    {
        $result = $this->queryPaymentStatus($reference);

        if (!$result) {
            return response()->json(['error' => 'Failed to query status'], 502);
        }

        return response()->json($result);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function post(string $path, array $payload): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->publicKey,
                'MerchantId'    => $this->merchantId,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . $path, $payload);

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('OPay API request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function queryPaymentStatus(string $reference): ?array
    {
        return $this->post('/api/v1/international/cashier/status/query', [
            'country'   => $this->country,
            'reference' => $reference,
        ]);
    }

    private function verifyWebhookSignature(array $payload, string $receivedSignature): bool
    {
        if (empty($receivedSignature)) {
            return false;
        }

        $jsonPayload       = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expectedSignature = hash_hmac('sha512', $jsonPayload, $this->secretKey);

        return hash_equals($expectedSignature, strtolower($receivedSignature));
    }

    /**
     * Credit the user's wallet — mirrors PaystackWebhookController::handleChargeSuccess()
     */
    private function handleSuccess(Deposit $deposit, array $payload): void
    {
        DB::transaction(function () use ($deposit, $payload) {

            // Lock rows exactly like Paystack/Monnify do
            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if ($lockedDeposit->processed_at !== null) {
                return; // Lost the race — already handled
            }

            $user = User::lockForUpdate()->find($lockedDeposit->user_id);

            if (!$user) {
                Log::error('OPay webhook: user not found', ['user_id' => $lockedDeposit->user_id]);
                return;
            }

            // Credit principal only (fee was already charged on top via total_kobo)
            $user->increment('balance_kobo', $lockedDeposit->amount_kobo);
            $balanceAfter = $user->fresh()->balance_kobo;

            // Ledger: deposit credit
            LedgerEntry::create([
                'uid'           => $user->id,
                'type'          => 'deposit',
                'amount_kobo'   => $lockedDeposit->amount_kobo,
                'balance_after' => $balanceAfter,
                'reference'     => $lockedDeposit->reference,
            ]);

            // Ledger: fee audit (no balance change — mirrors Paystack handler)
            LedgerEntry::create([
                'uid'           => $user->id,
                'type'          => 'transaction_fee',
                'amount_kobo'   => $lockedDeposit->transaction_fee,
                'balance_after' => $balanceAfter,
                'reference'     => $lockedDeposit->reference,
            ]);

            $lockedDeposit->update([
                'status'       => Deposit::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

            Log::info('OPay deposit processed', [
                'reference'     => $lockedDeposit->reference,
                'user_id'       => $user->id,
                'amount_kobo'   => $lockedDeposit->amount_kobo,
                'balance_after' => $balanceAfter,
            ]);

            try {
                $user->notify(new \App\Notifications\DepositConfirmed(
                    $lockedDeposit->amount_kobo,
                    $lockedDeposit->reference
                ));
            } catch (\Exception $e) {
                Log::warning('OPay DepositConfirmed notification failed', [
                    'deposit_id' => $lockedDeposit->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Mark the deposit failed — wallet is untouched.
     */
    private function handleFailed(Deposit $deposit, array $payload): void
    {
        $deposit->update(['status' => Deposit::STATUS_FAILED]);

        Log::info('OPay deposit failed', [
            'reference' => $deposit->reference,
            'reason'    => $payload['displayedFailure'] ?? 'unknown',
        ]);
    }
}