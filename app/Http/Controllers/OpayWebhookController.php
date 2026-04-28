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
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        $rawBody = $request->getContent();

        // ── Body must be valid JSON ─────────────────────────────────────
        $decoded = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Log::warning('OPay webhook: invalid JSON body', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Bad request'], 400);
        }

        // ── Signature verification ──────────────────────────────────────
        if (!OpayService::verifyWebhookSignature($rawBody)) {
            Log::warning('OPay webhook: invalid signature', ['ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // ── Validate required fields ────────────────────────────────────
        $payload   = $decoded['payload'] ?? [];
        $reference = trim($payload['reference'] ?? '');
        $status    = strtoupper(trim($payload['status'] ?? ''));
        $type      = $decoded['type'] ?? '';

        if (!$reference || !$status) {
            Log::warning('OPay webhook: missing reference or status', compact('payload'));
            return response()->json(['message' => 'Missing fields'], 400);
        }

        // Only handle transaction-status callbacks; ignore others silently
        if ($type !== 'transaction-status') {
            Log::info('OPay webhook: ignored callback type', ['type' => $type]);
            return response()->json(['message' => 'OK'], 200);
        }

        Log::info('OPay webhook received', [
            'reference' => $reference,
            'status'    => $status,
            'type'      => $type,
            'ip'        => $request->ip(),
        ]);

        // ── Look up deposit ─────────────────────────────────────────────
        $deposit = Deposit::where('reference', $reference)
            ->where('gateway', 'opay')
            ->first();

        if (!$deposit) {
            // Return 200 so OPay stops retrying — we simply don't know this ref
            Log::warning('OPay webhook: deposit not found', ['reference' => $reference]);
            return response()->json(['message' => 'OK'], 200);
        }

        // ── Idempotency guard (before any DB work) ──────────────────────
        if ($deposit->processed_at !== null) {
            Log::info('OPay webhook: already processed', ['reference' => $reference]);
            return response()->json(['message' => 'OK'], 200);
        }

        // ── Short-circuit non-success statuses without hitting OPay API ─
        if ($status === 'FAILED') {
            $this->handleFailed($deposit);
            return response()->json(['message' => 'OK'], 200);
        }

        if ($status !== 'SUCCESS') {
            Log::info('OPay webhook: unhandled status', compact('reference', 'status'));
            return response()->json(['message' => 'OK'], 200);
        }

        // ── Server-side verification (only for SUCCESS) ─────────────────
        try {
            $verification   = OpayService::status($reference);
            $verifiedStatus = strtoupper($verification['data']['status'] ?? 'FAILED');
            $verifiedAmount = (int) ($verification['data']['amount'] ?? 0);
        } catch (\Exception $e) {
            Log::error('OPay webhook: status check failed', [
                'reference' => $reference,
                'error'     => $e->getMessage(),
            ]);
            // Return 500 so OPay retries later
            return response()->json(['message' => 'Verification error'], 500);
        }

        if ($verifiedStatus !== 'SUCCESS') {
            Log::warning('OPay webhook: API verification did not confirm SUCCESS', [
                'reference'       => $reference,
                'verified_status' => $verifiedStatus,
            ]);
            $this->handleFailed($deposit);
            return response()->json(['message' => 'OK'], 200);
        }

        // ── Amount sanity check ─────────────────────────────────────────
        if ($verifiedAmount !== (int) $deposit->amount_kobo) {
            Log::critical('OPay webhook: amount mismatch — possible fraud', [
                'reference'       => $reference,
                'expected_kobo'   => $deposit->amount_kobo,
                'verified_kobo'   => $verifiedAmount,
            ]);
            // Do NOT credit; flag for manual review
            $deposit->update(['status' => Deposit::STATUS_REVIEW]);
            return response()->json(['message' => 'OK'], 200);
        }

        // ── 10. Process the successful deposit ─────────────────────────────
        $this->handleSuccess($deposit);

        return response()->json(['message' => 'OK'], 200);
    }

    // ─────────────────────────────────────────────────────────────────────────


    private function handleSuccess(Deposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {

            // Re-fetch with a row lock inside the transaction
            $lockedDeposit = Deposit::where('id', $deposit->id)
                ->lockForUpdate()
                ->first();

            // Double-check idempotency inside the transaction (race-condition guard)
            if ($lockedDeposit->processed_at !== null) {
                Log::info('OPay webhook: handleSuccess — already processed inside TX', [
                    'reference' => $lockedDeposit->reference,
                ]);
                return;
            }

            $user = User::lockForUpdate()->find($lockedDeposit->user_id);

            if (!$user) {
                Log::critical('OPay webhook: user not found for deposit', [
                    'user_id'   => $lockedDeposit->user_id,
                    'reference' => $lockedDeposit->reference,
                ]);
                // Throw so the transaction rolls back and OPay retries
                throw new \RuntimeException("User {$lockedDeposit->user_id} not found");
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

            // Only write a fee ledger entry if a fee was actually charged
            if ((int) $lockedDeposit->transaction_fee > 0) {
                LedgerEntry::create([
                    'uid'           => $user->id,
                    'type'          => 'transaction_fee',
                    'amount_kobo'   => $lockedDeposit->transaction_fee,
                    'balance_after' => $balanceAfter,
                    'reference'     => $lockedDeposit->reference,
                ]);
            }

            $lockedDeposit->update([
                'status'       => Deposit::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

            Log::info('OPay webhook: deposit credited', [
                'reference'     => $lockedDeposit->reference,
                'user_id'       => $user->id,
                'amount_kobo'   => $lockedDeposit->amount_kobo,
                'balance_after' => $balanceAfter,
            ]);
        });
    }

    private function handleFailed(Deposit $deposit): void
    {
        // Avoid overwriting a completed deposit (safety net)
        if ($deposit->status === Deposit::STATUS_COMPLETED) {
            Log::warning('OPay webhook: tried to fail a completed deposit', [
                'reference' => $deposit->reference,
            ]);
            return;
        }

        $deposit->update(['status' => Deposit::STATUS_FAILED]);

        Log::info('OPay webhook: deposit marked failed', [
            'reference' => $deposit->reference,
        ]);
    }
}