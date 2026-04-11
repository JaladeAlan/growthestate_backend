<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalConfirmed;
use App\Notifications\WithdrawalFailedNotification;
use App\Notifications\WithdrawalRejectedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class WithdrawalController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function dailyLimitKobo(): int
    {
        return (int) config('services.withdrawals.daily_limit_kobo', 50_000_000);
    }

    /**
     * Atomically refund a withdrawal back to the user's balance.
     * Handles ledger entry, daily-limit restoration, and status update.
     * Must be called inside a DB::transaction() with the withdrawal row
     * already locked via lockForUpdate().
     */
    private function refundWithdrawal(Withdrawal $withdrawal, string $reason): void
    {
        $user = \App\Models\User::lockForUpdate()->find($withdrawal->user_id);

        if (! $user) {
            Log::error('refundWithdrawal: user not found', [
                'withdrawal_id' => $withdrawal->id,
                'user_id'       => $withdrawal->user_id,
            ]);
            return;
        }

        // Restore balance
        $user->increment('balance_kobo', $withdrawal->amount_kobo);
        $balanceAfter = $user->fresh()->balance_kobo;

        $withdrawalDay = $withdrawal->created_at->toDateString();
        if ($user->withdrawal_day === $withdrawalDay) {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'withdrawal_daily_total_kobo' => DB::raw(
                        "GREATEST(0, withdrawal_daily_total_kobo - {$withdrawal->amount_kobo})"
                    ),
                ]);
        }

        // Immutable audit entry
        LedgerEntry::create([
            'uid'           => $user->id,
            'type'          => 'withdrawal_reversal',
            'amount_kobo'   => $withdrawal->amount_kobo,
            'balance_after' => $balanceAfter,
            'reference'     => $withdrawal->reference . '-REVERSAL',
            'note'          => $reason,
        ]);

        $withdrawal->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_at'      => now(),
            // FIX: stamp processed_at so any duplicate webhook / retry is
            // blocked by the idempotency guard in handlePaystackCallback().
            'processed_at'     => now(),
        ]);

        try {
            $user->notify(new WithdrawalRejectedNotification($withdrawal));
        } catch (\Exception $e) {
            Log::warning('WithdrawalRejectedNotification failed', [
                'withdrawal_id' => $withdrawal->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // USER: REQUEST WITHDRAWAL
    // POST /api/withdraw
    // ─────────────────────────────────────────────────────────────────────────

    public function requestWithdrawal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'amount'          => 'required|integer|min:500000',
            'transaction_pin' => 'required|digits:4',
        ]);

        if (! $user->transaction_pin) {
            return response()->json(['message' => 'Transaction PIN not set. Please set one in settings.'], 422);
        }

        if (! Hash::check($request->transaction_pin, $user->transaction_pin)) {
            return response()->json(['message' => 'Invalid transaction PIN.'], 422);
        }

        if (! $user->is_kyc_verified) {
            return response()->json(['error' => 'KYC verification is required before withdrawing funds.'], 403);
        }

        if (! $user->account_number || ! $user->bank_code) {
            return response()->json(['error' => 'Bank details are missing. Please update your bank details first.'], 400);
        }

        $referenceCode = 'WD-' . now()->format('Ymd-His') . '-' . Str::random(6);

        try {
            DB::transaction(function () use ($user, $request, $referenceCode) {
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

                // Debit balance immediately — funds are held until approval/rejection
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

                // Status is 'pending' — waits for admin approval before Paystack is called
                Withdrawal::create([
                    'user_id'     => $lockedUser->id,
                    'amount_kobo' => $request->amount,
                    'status'      => 'pending',
                    'reference'   => $referenceCode,
                ]);
            });

            return response()->json([
                'message'   => 'Withdrawal request submitted. It will be processed within 24 hours on business days.',
                'reference' => $referenceCode,
            ]);

        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            $clientErrors = ['Insufficient balance.', 'Daily withdrawal limit', 'Invalid transaction PIN'];
            foreach ($clientErrors as $msg) {
                if (str_starts_with($e->getMessage(), $msg)) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }

            return response()->json(['error' => 'Failed to submit withdrawal request. Please try again.'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // USER: GET WITHDRAWAL STATUS
    // GET /api/withdrawals/{reference}
    // ─────────────────────────────────────────────────────────────────────────

    public function getWithdrawalStatus(string $reference)
    {
        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (! $withdrawal) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        return response()->json([
            'reference'        => $withdrawal->reference,
            'status'           => $withdrawal->status,
            'amount_kobo'      => $withdrawal->amount_kobo,
            'rejection_reason' => $withdrawal->rejection_reason,
            'requested_at'     => $withdrawal->created_at,
            'reviewed_at'      => $withdrawal->reviewed_at,
            'updated_at'       => $withdrawal->updated_at,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN: LIST PENDING WITHDRAWALS
    // GET /api/admin/withdrawals
    // ─────────────────────────────────────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $request->validate([
            'status'   => 'sometimes|in:pending,approved,processing,completed,rejected,failed',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $withdrawals = Withdrawal::with(['user:id,name,email,account_number,bank_name,account_name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByRaw("CASE status
                WHEN 'pending'    THEN 0
                WHEN 'processing' THEN 1
                WHEN 'approved'   THEN 2
                WHEN 'failed'     THEN 3
                WHEN 'completed'  THEN 4
                ELSE 5
            END")
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json(['success' => true, 'data' => $withdrawals]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN: APPROVE A SINGLE WITHDRAWAL
    // POST /api/admin/withdrawals/{id}/approve
    // ─────────────────────────────────────────────────────────────────────────

    public function adminApprove(Request $request, int $id)
    {
        $admin = $request->user();

        DB::transaction(function () use ($id, $admin) {
            $withdrawal = Withdrawal::lockForUpdate()->findOrFail($id);

            if ($withdrawal->status !== 'pending') {
                abort(422, "Withdrawal is already {$withdrawal->status}. Only pending withdrawals can be approved.");
            }

            // Mark as processing before touching Paystack so the status is
            // never left as 'pending' if the API call partially succeeds.
            $withdrawal->update([
                'status'      => 'processing',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            try {
                $this->initiatePaystackTransfer(
                    $withdrawal->user,
                    $withdrawal->amount_kobo,
                    $withdrawal->reference
                );

                Log::info('Withdrawal approved and transfer initiated', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference'     => $withdrawal->reference,
                    'approved_by'   => $admin->id,
                ]);

            } catch (\Exception $e) {
                // Paystack call failed — mark as failed so it appears in the
                // admin queue for manual follow-up. Do NOT auto-refund here;
                // the transfer may have reached Paystack before the exception.
                // Admin must verify on Paystack dashboard before rejecting.
                $withdrawal->update(['status' => 'failed']);

                Log::error('Withdrawal approval: Paystack transfer failed', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference'     => $withdrawal->reference,
                    'error'         => $e->getMessage(),
                    'approved_by'   => $admin->id,
                ]);

                abort(502, 'Transfer initiation failed. Withdrawal marked as failed — verify on Paystack before rejecting.');
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal approved and transfer initiated.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN: REJECT A SINGLE WITHDRAWAL
    // POST /api/admin/withdrawals/{id}/reject
    // ─────────────────────────────────────────────────────────────────────────

    public function adminReject(Request $request, int $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $admin = $request->user();

        DB::transaction(function () use ($id, $admin, $request) {
            $withdrawal = Withdrawal::lockForUpdate()->findOrFail($id);

            if (! in_array($withdrawal->status, ['pending', 'failed'], true)) {
                abort(422, "Only pending or failed withdrawals can be rejected. Current status: {$withdrawal->status}.");
            }

            Log::info('Withdrawal rejected by admin', [
                'withdrawal_id' => $withdrawal->id,
                'reference'     => $withdrawal->reference,
                'rejected_by'   => $admin->id,
                'reason'        => $request->reason,
            ]);

            $this->refundWithdrawal($withdrawal, $request->reason);
        });

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal rejected and funds returned to user.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN: BATCH APPROVE ALL PENDING WITHDRAWALS
    // POST /api/admin/withdrawals/approve-all
    // ─────────────────────────────────────────────────────────────────────────

    public function adminApproveAll(Request $request)
    {
        $admin = $request->user();

        $pending = Withdrawal::where('status', 'pending')
            ->with('user')
            ->get();

        if ($pending->isEmpty()) {
            return response()->json(['message' => 'No pending withdrawals to process.']);
        }

        $results = ['approved' => [], 'failed' => []];

        foreach ($pending as $withdrawal) {
            // Each withdrawal gets its own transaction so one failure doesn't
            // roll back the others.
            try {
                DB::transaction(function () use ($withdrawal, $admin) {
                    $locked = Withdrawal::lockForUpdate()->find($withdrawal->id);

                    // Skip if status changed between the query and the lock
                    if ($locked->status !== 'pending') {
                        return;
                    }

                    $locked->update([
                        'status'      => 'processing',
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => now(),
                    ]);

                    $this->initiatePaystackTransfer(
                        $withdrawal->user,
                        $locked->amount_kobo,
                        $locked->reference
                    );
                });

                $results['approved'][] = $withdrawal->reference;

                Log::info('Batch withdrawal approved', [
                    'reference'   => $withdrawal->reference,
                    'approved_by' => $admin->id,
                ]);

            } catch (\Exception $e) {
                // Mark as failed so it's visible in admin queue
                Withdrawal::where('id', $withdrawal->id)
                    ->where('status', 'processing')
                    ->update(['status' => 'failed']);

                $results['failed'][] = [
                    'reference' => $withdrawal->reference,
                    'error'     => $e->getMessage(),
                ];

                Log::error('Batch withdrawal failed', [
                    'reference'   => $withdrawal->reference,
                    'error'       => $e->getMessage(),
                    'approved_by' => $admin->id,
                ]);
            }
        }

        return response()->json([
            'success'  => true,
            'approved' => count($results['approved']),
            'failed'   => count($results['failed']),
            'details'  => $results,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAYSTACK WEBHOOK
    // POST /api/paystack/webhook  (shared with deposit webhook)
    // ─────────────────────────────────────────────────────────────────────────

    public function handlePaystackCallback(Request $request)
    {
        $payload   = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json(['status' => 'missing_signature'], 400);
        }

        $computed = hash_hmac('sha512', $payload, config('services.paystack.secret_key'));
        if (! hash_equals($computed, $signature)) {
            Log::warning('Invalid Paystack webhook signature for withdrawal callback');
            abort(403, 'Unauthorized webhook');
        }

        $reference = $request->input('data.reference');
        $status    = $request->input('data.status');

        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (! $withdrawal) {
            // Not a withdrawal reference — may be a deposit, handled elsewhere
            return response()->json(['status' => 'not_a_withdrawal']);
        }

        if ($withdrawal->processed_at !== null) {
            Log::info('Paystack withdrawal webhook: already processed, skipping', [
                'reference'    => $reference,
                'processed_at' => $withdrawal->processed_at,
            ]);
            return response()->json(['status' => 'already_processed']);
        }

        DB::transaction(function () use ($withdrawal, $status) {
            $locked = Withdrawal::lockForUpdate()->find($withdrawal->id);

            if ($locked->processed_at !== null) {
                return;
            }

            if ($status === 'success') {
                $locked->update([
                    'status'       => 'completed',
                    'processed_at' => now(),
                ]);

                try {
                    $locked->user->notify(new WithdrawalConfirmed($locked));
                } catch (\Exception $e) {
                    Log::warning('WithdrawalConfirmed notification failed', [
                        'withdrawal_id' => $locked->id,
                        'error'         => $e->getMessage(),
                    ]);
                }

                Log::info('Withdrawal completed via Paystack webhook', [
                    'reference' => $locked->reference,
                    'user_id'   => $locked->user_id,
                ]);

            } elseif ($status === 'failed') {
                $this->refundWithdrawal($locked, 'Transfer failed on Paystack.');

                Log::warning('Withdrawal failed via Paystack webhook — refunded', [
                    'reference' => $locked->reference,
                    'user_id'   => $locked->user_id,
                ]);
            }
        });

        return response()->json(['status' => 'handled']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: PAYSTACK HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function initiatePaystackTransfer($user, int $amountKobo, string $referenceCode): void
    {
        $recipientCode = $user->recipient_code ?? $this->createPaystackRecipient($user);

        $res = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transfer', [
                'source'    => 'balance',
                'amount'    => $amountKobo,
                'recipient' => $recipientCode,
                'reference' => $referenceCode,
                'reason'    => 'SproutVest withdrawal',
            ]);

        if (! $res->successful()) {
            throw new \Exception(
                'Transfer initiation failed: ' . ($res->json('message') ?? $res->body())
            );
        }
    }

    protected function createPaystackRecipient($user): string
    {
        $res = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type'           => 'nuban',
                'name'           => $user->account_name ?? $user->name,
                'account_number' => $user->account_number,
                'bank_code'      => $user->bank_code,
                'currency'       => 'NGN',
            ]);

        if (! $res->successful()) {
            throw new \Exception(
                'Failed to create Paystack recipient: ' . ($res->json('message') ?? $res->body())
            );
        }

        $code = $res->json('data.recipient_code');
        $user->update(['recipient_code' => $code]);

        return $code;
    }
}