<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Payments\OpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpayWebhookController extends Controller
{
    /**
     * POST /opay/webhook
     *
     * OPay sends:
     *   Header: Signature   = HMAC-SHA512(timestamp + rawBody, secretKey)
     *   Header: MerchantId
     *   Header: RequestTimestamp
     */
    public function handle(Request $request)
    {
        $rawBody   = $request->getContent();
        $signature = $request->header('Signature', '');
        $timestamp = $request->header('RequestTimestamp', '');

        if (! OpayService::verifyWebhookSignature($rawBody, $signature, $timestamp)) {
            Log::warning('OPay webhook: invalid signature', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload   = json_decode($rawBody, true);
        $reference = $payload['reference'] ?? null;
        $status    = strtoupper($payload['status'] ?? '');

        Log::info('OPay webhook received', ['reference' => $reference, 'status' => $status]);

        if (! $reference) {
            return response()->json(['message' => 'Missing reference'], 400);
        }

        $deposit = Deposit::where('reference', $reference)
            ->where('gateway', 'opay')
            ->first();

        if (! $deposit) {
            // Acknowledge to stop retries — not our reference
            Log::warning('OPay webhook: deposit not found', ['reference' => $reference]);
            return response()->json(['message' => 'OK'], 200);
        }

        // Idempotency guard
        if ($deposit->processed_at !== null) {
            return response()->json(['message' => 'Already processed'], 200);
        }

        match ($status) {
            'SUCCESS' => $this->handleSuccess($deposit, $payload),
            'FAILED'  => $this->handleFailed($deposit, $payload),
            default   => Log::info('OPay webhook: unhandled status', [
                'reference' => $reference,
                'status'    => $status,
            ]),
        };

        return response()->json(['message' => 'OK'], 200);
    }

    /**
     * GET /deposit/opay/return
     *
     * OPay redirects the customer here after the cashier page.
     * Always re-verify server-side — never trust query params alone.
     */
    public function returnUrl(Request $request)
    {
        $reference = $request->query('reference');

        if (! $reference) {
            return redirect(config('app.frontend_url') . '/wallet?status=error&message=invalid_return');
        }

        try {
            $result    = OpayService::status($reference);
            $payStatus = strtoupper($result['data']['status'] ?? 'FAILED');
        } catch (\Exception $e) {
            Log::error('OPay returnUrl status check failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            // Non-fatal — let the webhook handle crediting; just redirect
            $payStatus = 'PENDING';
        }

        $frontendBase = config('app.frontend_url') . '/wallet?reference=' . $reference;

        return match ($payStatus) {
            'SUCCESS' => redirect($frontendBase . '&status=success'),
            'PENDING' => redirect($frontendBase . '&status=pending'),
            default   => redirect($frontendBase . '&status=failed'),
        };
    }

    /**
     * GET /deposit/opay/cancel
     *
     * OPay redirects here if the customer cancels on the cashier page.
     */
    public function cancel(Request $request)
    {
        $reference = $request->query('reference');

        if ($reference) {
            Deposit::where('reference', $reference)
                ->where('gateway', 'opay')
                ->where('status', Deposit::STATUS_PENDING)
                ->whereNull('processed_at')
                ->update(['status' => Deposit::STATUS_FAILED]);
        }

        return redirect(
            config('app.frontend_url') . '/wallet?status=cancelled'
            . ($reference ? '&reference=' . $reference : '')
        );
    }

    // =========================================================================
    // Private handlers
    // =========================================================================

    private function handleSuccess(Deposit $deposit, array $payload): void
    {
        DB::transaction(function () use ($deposit, $payload) {

            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            // Double-check inside the lock
            if ($lockedDeposit->processed_at !== null) {
                return;
            }

            $user = User::lockForUpdate()->find($lockedDeposit->user_id);

            if (! $user) {
                Log::error('OPay webhook: user not found', ['user_id' => $lockedDeposit->user_id]);
                return;
            }

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

            // Ledger: fee audit (balance unchanged — fee was charged on top via total_kobo)
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

    private function handleFailed(Deposit $deposit, array $payload): void
    {
        $deposit->update(['status' => Deposit::STATUS_FAILED]);

        Log::info('OPay deposit failed', [
            'reference' => $deposit->reference,
            'reason'    => $payload['reason'] ?? $payload['displayedFailure'] ?? 'unknown',
        ]);
    }
}