<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonnifyWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::info('Monnify webhook HIT');

        $signature = $request->header('monnify-signature');

        if (! $signature) {
            Log::warning('Missing Monnify signature');
            return response()->json(['status' => 'no_signature'], 400);
        }

        $computedSignature = hash_hmac(
            'sha512',
            $request->getContent(),
            config('services.monnify.secret_key')
        );

        if (! hash_equals($computedSignature, $signature)) {
            Log::warning('Invalid Monnify signature');
            return response()->json(['status' => 'invalid_signature'], 400);
        }

        $payload = $request->all();

        if (! isset($payload['eventType'], $payload['eventData'])) {
            Log::warning('Invalid Monnify payload structure');
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        if ($payload['eventType'] !== 'SUCCESSFUL_TRANSACTION') {
            return response()->json(['status' => 'ignored']);
        }

        $data = $payload['eventData'];

        $reference  = $data['paymentReference'] ?? null;
        $amountPaid = (int) round($data['amountPaid'] * 100); // convert to kobo

        if (! $reference) {
            Log::warning('Missing payment reference');
            return response()->json(['status' => 'missing_reference'], 400);
        }

      
        $deposit = Deposit::where('reference', $reference)->first();

        if (! $deposit) {
            Log::warning('Deposit not found for Monnify webhook', [
                'reference' => $reference
            ]);
            return response()->json(['status' => 'deposit_not_found'], 404);
        }

    
        if ($deposit->status === 'completed') {
            return response()->json(['status' => 'already_processed']);
        }

        if ($amountPaid !== (int) $deposit->total_kobo) {
            Log::critical('Monnify amount mismatch', [
                'reference' => $reference,
                'expected'  => $deposit->total_kobo,
                'paid'      => $amountPaid,
            ]);

            $deposit->update(['status' => 'failed']);
            return response()->json(['status' => 'amount_mismatch'], 400);
        }

        DB::transaction(function () use ($deposit) {

            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if ($lockedDeposit->status === 'completed') {
                return;
            }

            $user = User::where('id', $lockedDeposit->user_id)
                ->lockForUpdate()
                ->first();

            // Credit only principal
            $user->balance_kobo += $lockedDeposit->amount_kobo;
            $user->save();

            $lockedDeposit->update([
                'status' => 'completed'
            ]);

            // Ledger: deposit
            DB::table('ledger_entries')->insert([
                'uid'           => $user->id,
                'type'          => 'deposit',
                'amount_kobo'   => $lockedDeposit->amount_kobo,
                'balance_after' => $user->balance_kobo,
                'reference'     => $lockedDeposit->reference,
                'created_at'    => now(),
            ]);

            // Ledger: transaction fee
            DB::table('ledger_entries')->insert([
                'uid'           => $user->id,
                'type'          => 'transaction_fee',
                'amount_kobo'   => $lockedDeposit->transaction_fee,
                'balance_after' => $user->balance_kobo,
                'reference'     => $lockedDeposit->reference,
                'created_at'    => now(),
            ]);
        });

        return response()->json(['status' => 'processed']);
    }
}
