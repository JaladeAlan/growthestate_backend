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
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();

        $signature = $request->header('Signature');
        if (! $signature && $request->header('Authorization')) {
            if (preg_match('/Bearer\s+(.*)$/i', $request->header('Authorization'), $matches)) {
                $signature = $matches[1];
            }
        }

        Log::info('OPay webhook received', [
            'signature' => $signature,
            'body_hash' => hash('sha256', $rawBody),
        ]);

        if (! $signature) {
            Log::warning('OPay webhook: missing signature');
            return response()->json(['status' => 'missing_signature'], 400);
        }

        if (! OpayService::verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('OPay webhook: invalid signature');
            return response()->json(['status' => 'invalid_signature'], 403);
        }

        $payload     = $request->all();
        $orderStatus = strtoupper($payload['data']['status'] ?? '');
        $reference   = $payload['data']['reference'] ?? null;
        $amountKobo  = isset($payload['data']['amount'])
            ? (int) bcmul((string) $payload['data']['amount'], '100', 0)
            : 0;

        Log::info('OPay webhook payload', [
            'reference' => $reference,
            'status' => $orderStatus,
            'amount_kobo' => $amountKobo,
            'currency' => $payload['data']['currency'] ?? null,
        ]);

        if (($payload['data']['currency'] ?? '') !== 'NGN') {
            Log::warning('OPay webhook: invalid currency', $payload);
            return response()->json(['status' => 'invalid_currency'], 400);
        }

        if (! $reference || $amountKobo <= 0) {
            Log::warning('OPay webhook: bad payload', $payload);
            return response()->json(['status' => 'bad_payload'], 400);
        }

        $deposit = Deposit::where('reference', $reference)->first();
        if (! $deposit) {
            Log::warning('OPay webhook: deposit not found', ['reference' => $reference]);
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($orderStatus === 'SUCCESS') {
            Log::info('OPay webhook: processing deposit', ['reference' => $reference]);
            $this->processVerifiedDeposit($deposit, $amountKobo);
        }

        return response()->json(['status' => 'ok']);
    }

    public function processVerifiedDeposit(Deposit $deposit, ?int $amountKobo = null): void
    {
        DB::transaction(function () use ($deposit, $amountKobo) {

            $deposit = Deposit::where('id', $deposit->id)->lockForUpdate()->first();
            Log::info('Processing deposit transaction', ['reference' => $deposit->reference]);

            if ($deposit->processed_at !== null || $deposit->status === 'completed') {
                Log::info('Deposit already processed', ['reference' => $deposit->reference]);
                return;
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

            Log::info('Crediting user wallet', [
                'user_id' => $user->id,
                'amount_kobo' => $deposit->amount_kobo,
            ]);

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
                Log::info('Applying transaction fee', [
                    'user_id' => $user->id,
                    'fee_kobo' => $deposit->transaction_fee,
                ]);

                $user->decrement('balance_kobo', $deposit->transaction_fee);
                $balanceAfter = $user->fresh()->balance_kobo;

                LedgerEntry::create([
                    'uid'           => $user->id,
                    'type'          => 'transaction_fee',
                    'amount_kobo'   => -$deposit->transaction_fee,
                    'balance_after' => $balanceAfter,
                    'reference'     => $deposit->reference,
                ]);
            }

            $deposit->update([
                'status'       => 'completed',
                'processed_at' => now(),
            ]);

            Log::info('Deposit processed successfully', [
                'reference' => $deposit->reference,
                'user_id' => $user->id,
                'balance_after' => $balanceAfter,
            ]);
        });
    }
}