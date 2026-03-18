<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Payments\OpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming OPay webhook events AND shared deposit-credit logic.
 *
 * Route (public, no JWT middleware):
 *   POST /api/opay/webhook
 */
class OpayWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $rawBody   = $request->getContent();
        $signature = $request->header('Signature');

        if (! $signature) {
            Log::warning('OPay webhook: missing Signature header');
            return response()->json(['status' => 'missing_signature'], 400);
        }

        if (! OpayService::verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('OPay webhook: invalid signature');
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        $payload     = $request->all();
        $orderStatus = strtoupper($payload['data']['status'] ?? '');

        Log::info('OPay webhook received', [
            'type'      => $payload['type'] ?? null,
            'reference' => $payload['data']['reference'] ?? null,
            'status'    => $orderStatus,
        ]);

        if ($orderStatus !== 'SUCCESS') {
            return response()->json(['status' => 'ignored']);
        }

        $reference  = $payload['data']['reference'] ?? null;
        $amountNgn  = (float) ($payload['data']['amount'] ?? 0);
        $amountKobo = (int) round($amountNgn * 100);

        if (! $reference || $amountKobo <= 0) {
            Log::warning('OPay webhook: missing reference or amount', $payload);
            return response()->json(['status' => 'bad_payload'], 400);
        }

        $deposit = Deposit::where('reference', $reference)->first();

        if (! $deposit) {
            Log::warning('OPay webhook: deposit not found', ['reference' => $reference]);
            return response()->json(['status' => 'not_found'], 404);
        }

        $this->processVerifiedDeposit($deposit, $amountKobo);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Credit the user wallet for a confirmed OPay payment.
     * Idempotent — safe to call multiple times (webhook + active poll).
     *
     * @param  int|null  $amountKobo  Omit to skip the amount integrity check.
     */
    public function processVerifiedDeposit(Deposit $deposit, ?int $amountKobo = null): void
    {
        DB::transaction(function () use ($deposit, $amountKobo) {

            $deposit = Deposit::where('id', $deposit->id)->lockForUpdate()->first();

            if ($deposit->processed_at !== null) {
                return; // Already handled — idempotency guard
            }

            if ($amountKobo !== null && $amountKobo !== (int) $deposit->total_kobo) {
                Log::critical('OPay amount mismatch', [
                    'reference' => $deposit->reference,
                    'expected'  => $deposit->total_kobo,
                    'received'  => $amountKobo,
                ]);
                $deposit->update(['status' => 'failed']);
                return;
            }

            $user = User::lockForUpdate()->find($deposit->user_id);

            if (! $user) {
                Log::error('OPay: user not found', ['user_id' => $deposit->user_id]);
                return;
            }

            $user->increment('balance_kobo', $deposit->amount_kobo);
            $balanceAfter = $user->fresh()->balance_kobo;

            LedgerEntry::create([
                'uid'           => $user->id,
                'type'          => 'deposit',
                'amount_kobo'   => $deposit->amount_kobo,
                'balance_after' => $balanceAfter,
                'reference'     => $deposit->reference,
            ]);

            if ($deposit->transaction_fee > 0) {
                LedgerEntry::create([
                    'uid'           => $user->id,
                    'type'          => 'transaction_fee',
                    'amount_kobo'   => $deposit->transaction_fee,
                    'balance_after' => $balanceAfter,
                    'reference'     => $deposit->reference,
                ]);
            }

            $deposit->update(['status' => 'completed', 'processed_at' => now()]);

            Log::info('OPay deposit credited', [
                'reference'     => $deposit->reference,
                'user_id'       => $user->id,
                'amount_kobo'   => $deposit->amount_kobo,
                'balance_after' => $balanceAfter,
            ]);
        });
    }
}