<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Always verify signature
        $payload   = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json(['error' => 'Missing signature.'], 400);
        }

        $computed = hash_hmac('sha512', $payload, config('services.paystack.secret_key'));
        if (! hash_equals($computed, $signature)) {
            Log::warning('Paystack webhook: invalid signature');
            abort(403);
        }

        $event = $request->input('event');

        match ($event) {
            'charge.success'     => $this->handleChargeSuccess($request->input('data')),
            'transfer.success',
            'transfer.failed',
            'transfer.reversed'  => app(\App\Http\Controllers\WithdrawalController::class)
                                        ->handlePaystackCallback($request),
            default              => Log::info('Unhandled Paystack event', ['event' => $event]),
        };

        return response()->json(['status' => 'ok']);
    }

    private function handleChargeSuccess(array $data): void
    {
        $reference = $data['reference'] ?? null;
        $amountKobo = (int) ($data['amount'] ?? 0);

        if (! $reference || $amountKobo <= 0) {
            Log::warning('Paystack charge.success: missing reference or amount', $data);
            return;
        }

        DB::transaction(function () use ($reference, $amountKobo, $data) {
            // Lock the deposit row so concurrent webhooks queue behind this one
            $deposit = Deposit::where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $deposit) {
                Log::warning('Paystack charge.success: deposit not found', ['reference' => $reference]);
                return;
            }

            if ($deposit->processed_at !== null) {
                Log::info('Paystack charge.success: already processed, skipping', ['reference' => $reference]);
                return;
            }

            if ($amountKobo !== (int) $deposit->total_kobo) {
                Log::critical('Paystack charge.success: amount mismatch', [
                    'reference' => $reference,
                    'expected'  => $deposit->total_kobo,
                    'paid'      => $amountKobo,
                ]);

                $deposit->update(['status' => Deposit::STATUS_REVIEW]);
                return;
            }

            $user = User::lockForUpdate()->find($deposit->user_id);
            if (! $user) {
                Log::error('Paystack charge.success: user not found', ['user_id' => $deposit->user_id]);
                return;
            }

            // Credit main wallet
            $user->increment('balance_kobo', $deposit->amount_kobo);
            $balanceAfter = $user->fresh()->balance_kobo;

            LedgerEntry::create([
                'uid'           => $user->id,
                'type'          => 'deposit',
                'amount_kobo'   => $deposit->amount_kobo,
                'balance_after' => $balanceAfter,
                'reference'     => $reference,
            ]);

            LedgerEntry::create([
                'uid'           => $user->id,
                'type'          => 'transaction_fee',
                'amount_kobo'   => $deposit->transaction_fee,
                'balance_after' => $user->fresh()->balance_kobo,
                'reference'     => $deposit->reference,
                ]);

            $deposit->update([
                'status'       => 'completed',
                'processed_at' => now(),
            ]);

            Log::info('Deposit processed', [
                'reference'    => $reference,
                'user_id'      => $user->id,
                'amount_kobo'  => $deposit->amount_kobo,
                'balance_after' => $balanceAfter,
            ]);

            $user->notify(new \App\Notifications\DepositConfirmed(
                $deposit->amount_kobo,
                $deposit->reference
            ));
        });
    }
}