<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalConfirmed;
use App\Notifications\WithdrawalFailedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;


class WithdrawalController extends Controller
{
    private function dailyLimitKobo(): int
    {
        return (int) config('services.withdrawals.daily_limit_kobo', 50_000_000);
    }

    public function requestWithdrawal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'amount'          => 'required|integer|min:500000',
            'transaction_pin' => 'required|digits:4',
        ]);

        if (! $user->transaction_pin) {
            return response()->json(['message' => 'Transaction pin not set. Please set one in settings.'], 422);
        }

        if (! Hash::check($request->transaction_pin, $user->transaction_pin)) {
            return response()->json(['message' => 'Invalid transaction PIN.'], 422);
        }

        if (! $user->is_kyc_verified) {
            return response()->json(['error' => 'KYC verification is required before withdrawing funds.'], 403);
        }

        if (! $user->account_number || ! $user->bank_code) {
            return response()->json(['error' => 'Bank details are missing.'], 400);
        }

        $referenceCode = 'WD-' . now()->format('Ymd-His') . '-' . Str::random(6);

        try {
            $transferred = false;

            DB::transaction(function () use ($user, $request, $referenceCode, &$transferred) {
                $lockedUser = \App\Models\User::lockForUpdate()->find($user->id);

                if ($lockedUser->balance_kobo < $request->amount) {
                    throw new \Exception('Insufficient balance.');
                }

                $today = now()->toDateString();

                if ($lockedUser->withdrawal_day !== $today) {
                    $lockedUser->withdrawal_daily_total_kobo = 0;
                    $lockedUser->withdrawal_day              = $today;
                }

                $newDailyTotal = $lockedUser->withdrawal_daily_total_kobo + $request->amount;

                if ($newDailyTotal > $this->dailyLimitKobo()) {
                    $remaining = max(0, $this->dailyLimitKobo() - $lockedUser->withdrawal_daily_total_kobo);
                    throw new \Exception(sprintf(
                        'Daily withdrawal limit of ₦%s reached. Remaining today: ₦%s.',
                        number_format($this->dailyLimitKobo() / 100, 2),
                        number_format($remaining / 100, 2)
                    ));
                }

                $lockedUser->balance_kobo                -= $request->amount;
                $lockedUser->withdrawal_daily_total_kobo  = $newDailyTotal;
                $lockedUser->withdrawal_day               = $today;
                $lockedUser->save();

                $balanceAfter = $lockedUser->balance_kobo;

                LedgerEntry::create([
                    'uid'           => $lockedUser->id,
                    'type'          => 'withdrawal',
                    'amount_kobo'   => $request->amount,
                    'balance_after' => $balanceAfter,
                    'reference'     => $referenceCode,
                ]);

                Withdrawal::create([
                    'user_id'     => $lockedUser->id,
                    'amount_kobo' => $request->amount,
                    'status'      => 'pending',
                    'reference'   => $referenceCode,
                ]);

                $transferred = true;
            });

            if (! $transferred) {
                return response()->json(['error' => 'Withdrawal could not be processed.'], 500);
            }

            if (config('services.paystack.test_mode')) {
                return $this->simulateTestWithdrawal($referenceCode);
            }

            $user->refresh();
            $this->initiatePaystackTransfer($user, $request->amount, $referenceCode);

            return response()->json([
                'message'   => 'Withdrawal request initiated.',
                'reference' => $referenceCode,
            ]);

        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);

            $clientErrors = ['Insufficient balance.', 'Daily withdrawal limit', 'Invalid transaction PIN'];
            foreach ($clientErrors as $msg) {
                if (str_starts_with($e->getMessage(), $msg)) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }

            return response()->json(['error' => 'Failed to initiate withdrawal.'], 500);
        }
    }

    public function handlePaystackCallback(Request $request)
    {
        $payload   = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json(['status' => 'missing_signature'], 400);
        }

        $computed = hash_hmac('sha512', $payload, config('services.paystack.secret_key'));
        if (! hash_equals($computed, $signature)) {
            Log::warning('Invalid Paystack webhook signature');
            abort(403, 'Unauthorized webhook');
        }

        $reference  = $request->input('data.reference');
        $status     = $request->input('data.status');
        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (! $withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        if (in_array($withdrawal->status, ['completed', 'failed'], true)) {
            return response()->json(['status' => 'already_processed']);
        }

        DB::transaction(function () use ($withdrawal, $status) {
            $withdrawal = Withdrawal::lockForUpdate()->find($withdrawal->id);
            if (in_array($withdrawal->status, ['completed', 'failed'], true)) {
                return;
            }

            if ($status === 'success') {
                $withdrawal->update(['status' => 'completed']);
                try { $withdrawal->user->notify(new WithdrawalConfirmed($withdrawal)); } catch (\Exception) {}

            } elseif ($status === 'failed') {
                $withdrawal->user->increment('balance_kobo', $withdrawal->amount_kobo);
                $balanceAfter = $withdrawal->user->fresh()->balance_kobo;

                $this->decrementDailyTotal($withdrawal->user, $withdrawal->amount_kobo);

                LedgerEntry::create([
                    'uid'           => $withdrawal->user->id,
                    'type'          => 'withdrawal_reversal',
                    'amount_kobo'   => $withdrawal->amount_kobo,
                    'balance_after' => $balanceAfter,
                    'reference'     => $withdrawal->reference . '-REVERSAL',
                ]);

                $withdrawal->update(['status' => 'failed']);
                try { $withdrawal->user->notify(new WithdrawalFailedNotification($withdrawal)); } catch (\Exception) {}
            }
        });

        return response()->json(['status' => 'handled']);
    }

    private function decrementDailyTotal($user, int $amountKobo): void
    {
        $today = now()->toDateString();

        DB::table('users')
            ->where('id', $user->id)
            ->where('withdrawal_day', $today)
            ->update([
                'withdrawal_daily_total_kobo' => DB::raw(
                    "GREATEST(0, withdrawal_daily_total_kobo - {$amountKobo})"
                ),
            ]);
    }

    public function retryPendingWithdrawals()
    {
        $claimed = DB::table('withdrawals')
            ->where('status', 'pending')
            ->update(['status' => 'processing', 'updated_at' => now()]);

        if ($claimed === 0) {
            return response()->json(['message' => 'No pending withdrawals to retry.']);
        }

        $withdrawals = Withdrawal::where('status', 'processing')->with('user')->get();

        foreach ($withdrawals as $withdrawal) {
            if (! $withdrawal->reference) {
                Log::error('Skipping withdrawal — missing reference', ['id' => $withdrawal->id]);
                $withdrawal->update(['status' => 'pending']);
                continue;
            }

            try {
                $this->initiatePaystackTransfer(
                    $withdrawal->user,
                    $withdrawal->amount_kobo,
                    $withdrawal->reference
                );
                Log::info('Withdrawal retried', ['reference' => $withdrawal->reference]);
            } catch (\Exception $e) {
                Log::error('Retry failed', ['reference' => $withdrawal->reference, 'error' => $e->getMessage()]);
                $withdrawal->update(['status' => 'pending']);
            }
        }

        return response()->json(['message' => "Retried {$claimed} withdrawals."]);
    }

    protected function simulateTestWithdrawal(string $referenceCode)
    {
        $withdrawal = Withdrawal::firstWhere('reference', $referenceCode);

        if ($withdrawal && ! in_array($withdrawal->status, ['completed', 'failed'], true)) {
            DB::transaction(function () use ($withdrawal) {
                $withdrawal->update(['status' => 'completed']);
                try { $withdrawal->user->notify(new WithdrawalConfirmed($withdrawal)); } catch (\Exception) {}
            });
        }

        return response()->json([
            'message'   => 'Test mode withdrawal successful.',
            'reference' => $referenceCode,
        ]);
    }

    protected function initiatePaystackTransfer($user, int $amountKobo, string $referenceCode)
    {
        $recipientCode = $user->recipient_code ?? $this->createPaystackRecipient($user);

        $res = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transfer', [
                'source'    => 'balance',
                'amount'    => $amountKobo,
                'recipient' => $recipientCode,
                'reference' => $referenceCode,
            ]);

        if (! $res->successful()) {
            throw new \Exception('Transfer initiation failed: ' . ($res->json()['message'] ?? 'unknown'));
        }
    }

    protected function createPaystackRecipient($user): string
    {
        $res = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type'           => 'nuban',
                'name'           => $user->name,
                'account_number' => $user->account_number,
                'bank_code'      => $user->bank_code,
                'currency'       => 'NGN',
            ]);

        if (! $res->successful()) {
            throw new \Exception('Failed to create recipient: ' . ($res->json()['message'] ?? 'unknown'));
        }

        $code = $res->json()['data']['recipient_code'];
        $user->update(['recipient_code' => $code]);
        return $code;
    }

    public function getWithdrawalStatus(string $reference)
    {
        $withdrawal = Withdrawal::firstWhere('reference', $reference);
        if (! $withdrawal) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        return response()->json([
            'status'       => $withdrawal->status,
            'amount_kobo'  => $withdrawal->amount_kobo,
            'requested_at' => $withdrawal->created_at,
            'updated_at'   => $withdrawal->updated_at,
        ]);
    }
}