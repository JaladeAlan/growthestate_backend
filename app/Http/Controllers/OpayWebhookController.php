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
        $rawBody  = $request->getContent();
        $decoded  = json_decode($rawBody, true);

        $signature = $request->header('Signature');
        $timestamp = $request->header('RequestTimestamp');

        if (!OpayService::verifyWebhookSignature($rawBody)) {
            Log::warning('OPay webhook: invalid signature', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload   = $decoded['payload'] ?? [];
        $reference = $payload['reference'] ?? null;
        $status    = strtoupper($payload['status'] ?? '');

        Log::info('OPay webhook received', compact('reference', 'status'));

        if (!$reference) {
            return response()->json(['message' => 'Missing reference'], 400);
        }

        $deposit = Deposit::where('reference', $reference)
            ->where('gateway', 'opay')
            ->first();

        if (!$deposit) {
            Log::warning('Deposit not found', ['reference' => $reference]);
            return response()->json(['message' => 'OK'], 200);
        }

        if ($deposit->processed_at !== null) {
            return response()->json(['message' => 'Already processed'], 200);
        }

        // VERIFY WITH OPay API (CRITICAL)
        try {
            $verification = OpayService::status($reference);
            $verifiedStatus = strtoupper($verification['data']['status'] ?? 'FAILED');

            if ($verifiedStatus !== 'SUCCESS') {
                Log::warning('OPay verification failed', ['reference' => $reference]);
                return response()->json(['message' => 'Verification failed'], 400);
            }
        } catch (\Exception $e) {
            Log::error('OPay status check failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Verification error'], 500);
        }

        match ($status) {
            'SUCCESS' => $this->handleSuccess($deposit),
            'FAILED'  => $this->handleFailed($deposit),
            default   => Log::info('Unhandled status', compact('reference', 'status')),
        };

        return response()->json(['message' => 'OK'], 200);
    }

    private function handleSuccess(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {

            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            if ($lockedDeposit->processed_at !== null) {
                return;
            }

            $user = User::lockForUpdate()->find($lockedDeposit->user_id);

            if (!$user) {
                Log::error('User not found', ['user_id' => $lockedDeposit->user_id]);
                return;
            }

            $user->increment('balance_kobo', $lockedDeposit->amount_kobo);
            $balanceAfter = $user->fresh()->balance_kobo;

            LedgerEntry::create([
                'uid'           => $user->id,
                'type'          => 'deposit',
                'amount_kobo'   => $lockedDeposit->amount_kobo,
                'balance_after' => $balanceAfter,
                'reference'     => $lockedDeposit->reference,
            ]);

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

            Log::info('Deposit processed', [
                'reference' => $lockedDeposit->reference,
                'user_id'   => $user->id,
            ]);
        });
    }

    private function handleFailed(Deposit $deposit): void
    {
        $deposit->update(['status' => Deposit::STATUS_FAILED]);

        Log::info('Deposit failed', [
            'reference' => $deposit->reference,
        ]);
    }
}