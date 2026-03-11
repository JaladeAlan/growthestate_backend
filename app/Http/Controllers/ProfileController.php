<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * Handles user profile and account-level reads.
 *
 * Routes:
 *   GET  /me
 *   GET  /user/account-status
 *   PUT  /user/bank-details
 *   GET  /user/stats
 *   GET  /user/lands
 */
class ProfileController extends Controller
{
    // =========================================================================
    // GET /me
    // =========================================================================
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->makeHidden([
            'password',
            'transaction_pin',
            'pin_reset_code',
        ]);

        $user->pin_is_set       = $this->userHasPin($request->user());
        $user->is_kyc_verified  = $this->isKycVerified($request->user());
        $user->kyc_status       = $this->resolveKycStatus($request->user());

        return response()->json([
            'success' => true,
            'data'    => $user,
        ]);
    }

    // =========================================================================
    // GET /user/account-status
    // =========================================================================
    public function accountStatus(Request $request): JsonResponse
    {
        $user      = $request->user();
        $hasPin    = $this->userHasPin($user);
        $kycStatus = $this->resolveKycStatus($user);
        $kycPassed = $this->isKycVerified($user);

        return response()->json([
            'success' => true,
            'data'    => [
                'pin_is_set'       => $hasPin,
                'is_kyc_verified'  => $kycPassed,
                'kyc_status'       => $kycStatus,   // none | pending | approved | rejected | resubmit
                'can_transact'     => $hasPin && $kycPassed,
                'blocking_reasons' => $this->blockingReasons($hasPin, $kycStatus),
            ],
        ]);
    }

    // =========================================================================
    // PUT /user/bank-details
    // =========================================================================
    public function updateBankDetails(Request $request): JsonResponse
    {
        $request->validate([
            'bank_code'      => 'required|string|max:10',
            'account_number' => 'required|digits:10',
        ]);

        $user = $request->user();

        // Resolve account name via Paystack
        $resolve = Http::withToken(config('services.paystack.secret_key'))
            ->get('https://api.paystack.co/bank/resolve', [
                'account_number' => $request->account_number,
                'bank_code'      => $request->bank_code,
            ]);

        if ($resolve->failed() || !$resolve->json('status')) {
            return response()->json([
                'success' => false,
                'message' => 'Could not verify account details. Please check and try again.',
            ], 422);
        }

        $accountName = $resolve->json('data.account_name');

        // Create / update Paystack transfer recipient
        $recipient = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type'           => 'nuban',
                'name'           => $accountName,
                'account_number' => $request->account_number,
                'bank_code'      => $request->bank_code,
                'currency'       => 'NGN',
            ]);

        if ($recipient->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register bank details. Please try again.',
            ], 422);
        }

        $user->update([
            'bank_code'      => $request->bank_code,
            'account_number' => $request->account_number,
            'account_name'   => $accountName,
            'recipient_code' => $recipient->json('data.recipient_code'),
            'bank_verified'  => true,
        ]);

        return response()->json([
            'success'      => true,
            'message'      => 'Bank details updated.',
            'account_name' => $accountName,
        ]);
    }

    // =========================================================================
    // GET /user/stats
    // =========================================================================
  public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $investedData = Purchase::where('user_id', $userId)
            ->whereIn('status', ['completed', 'partially_sold'])
            ->selectRaw('SUM(total_amount_paid_kobo) as total_paid, SUM(total_amount_received_kobo) as total_received')
            ->first();

        $totalPaid     = $investedData->total_paid     ?? 0;
        $totalReceived = $investedData->total_received ?? 0;
        $totalInvested = $totalPaid - $totalReceived;

        $totalUnits  = Purchase::where('user_id', $userId)->sum('units');
        $totalLands  = Purchase::where('user_id', $userId)->where('units', '>', 0)->count();

        $totalWithdrawn  = \App\Models\Withdrawal::where('user_id', $userId)
            ->where('status', 'completed')
            ->sum('amount_kobo');
        $pendingWithdraw = \App\Models\Withdrawal::where('user_id', $userId)
            ->where('status', 'pending')
            ->count();

        $portfolio = \App\Services\PortfolioService::summary($userId);

        return response()->json([
            'success' => true,
            'data'    => [
                'balance_kobo'                  => $request->user()->balance_kobo ?? 0,
                'total_invested_kobo'           => $totalInvested,
                'total_received_kobo'           => (int) $totalReceived,
                'current_portfolio_value_kobo'  => $portfolio['current_portfolio_value_kobo'],
                'current_portfolio_value_naira' => $portfolio['current_portfolio_value_naira'],
                'total_profit_loss_kobo'        => $portfolio['total_profit_loss_kobo'],
                'total_profit_loss_naira'       => $portfolio['total_profit_loss_naira'],
                'profit_loss_percent'           => $portfolio['profit_loss_percent'],
                'units_owned'                   => $totalUnits,
                'lands_owned'                   => $totalLands,
                'total_withdrawn_kobo'          => $totalWithdrawn,
                'pending_withdrawals'           => $pendingWithdraw,
                'pin_is_set'                    => $this->userHasPin($request->user()),
                'is_kyc_verified'               => $this->isKycVerified($request->user()),
                'kyc_status'                    => $this->resolveKycStatus($request->user()),
            ],
        ]);
    }
    // =========================================================================
    // GET /user/lands
    // =========================================================================
    public function lands(Request $request): JsonResponse
    {
        $holdings = Purchase::with('land:id,title,location,size')
            ->where('user_id', $request->user()->id)
            ->where('units', '>', 0)
            ->get(['land_id', 'units', 'total_amount_paid_kobo', 'purchase_date']);

        return response()->json(['success' => true, 'data' => $holdings]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function userHasPin($user): bool
    {
        return !empty($user->transaction_pin);
    }

    private function isKycVerified($user): bool
    {
        return $this->resolveKycStatus($user) === 'approved';
    }

    private function resolveKycStatus($user): string
    {
        // If the user model carries a direct kyc_status column, trust it.
        if (!empty($user->kyc_status)) {
            return $user->kyc_status;
        }

        // Otherwise check the kyc_verifications table for the latest record.
        $kyc = \App\Models\KycVerification::where('user_id', $user->id)
            ->latest()
            ->value('status');

        return $kyc ?? 'none';
    }

    /**
     * Returns a human-readable list of reasons why the user cannot transact.
     * Empty array means they are fully cleared.
     */
    private function blockingReasons(bool $hasPin, string $kycStatus): array
    {
        $reasons = [];

        if (!$hasPin) {
            $reasons[] = 'Transaction PIN not set.';
        }

        match ($kycStatus) {
            'none'     => $reasons[] = 'KYC verification not submitted.',
            'pending'  => $reasons[] = 'KYC verification is under review.',
            'rejected' => $reasons[] = 'KYC verification was rejected. Please resubmit.',
            'resubmit' => $reasons[] = 'KYC resubmission required.',
            default    => null,
        };

        return $reasons;
    }
}