<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesAccountStatus;
use App\Models\KycVerification;
use App\Models\Purchase;
use App\Models\SanctionsEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    use ResolvesAccountStatus;

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
            'success'    => true,
            'data'       => $user,
            'expires_at' => now()->addMinutes((int) config('jwt.ttl'))->getTimestamp() * 1000,
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
            'bank_name'      => 'nullable|string|max:100',
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

        // ── Identity check ──────────────────────────────────────────────────
        // bank/resolve only confirms the account EXISTS at the bank — it says
        // nothing about whether it belongs to this user. Without this check,
        // any authenticated user could point their withdrawal payouts at a
        // third party's bank account. Compare against the KYC-approved legal
        // name where available (most reliable), falling back to the
        // self-reported profile name otherwise.
        $identityName = KycVerification::where('user_id', $user->id)
            ->where('status', 'approved')
            ->value('full_name') ?? $user->name;

        if (! $this->nameMatchesAccount($identityName, $accountName)) {
            Log::warning('Bank details update rejected: account name does not match user identity', [
                'user_id'       => $user->id,
                'identity_name' => $identityName,
                // account_name is third-party bank data, not logged verbatim
                // to avoid dumping another person's name into app logs.
                'match_score'   => $this->nameMatchScore($identityName, $accountName),
            ]);

            return response()->json([
                'success' => false,
                'message' => "The account name on this bank account doesn't match your profile name. "
                    . 'If this is a joint or family account, please contact support to verify it manually.',
            ], 422);
        }

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
            'bank_name'      => $recipient->json('data.details.bank_name') ?? $request->bank_name,
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
    // userHasPin / isKycVerified / resolveKycStatus / blockingReasons now
    // live in Concerns\ResolvesAccountStatus (used via the trait above) —
    // extracted so AuthController::login() can share the same logic.

    /**
     * Minimum fuzzy-match score (0–100) required to accept a bank account
     * as belonging to the user. Deliberately looser than the sanctions
     * screening threshold (85) — this is checking for an honest name-order/
     * middle-name/initials mismatch, not trying to avoid false positives
     * against a watchlist. Verified against realistic Nigerian bank-listing
     * patterns (surname-first ordering, dropped middle names, initials)
     * to leave headroom above this threshold; a genuinely different
     * person's name scores well below it.
     */
    private const NAME_MATCH_THRESHOLD = 55;

    private function nameMatchesAccount(string $identityName, ?string $accountName): bool
    {
        if (! $accountName) {
            return false;
        }

        return $this->nameMatchScore($identityName, $accountName) >= self::NAME_MATCH_THRESHOLD;
    }

    /**
     * Fuzzy similarity score (0–100) between the user's identity name and
     * the bank-resolved account name. Same token-sort + similar_text +
     * Levenshtein approach used by SanctionsScreeningService::fuzzyScore(),
     * kept local here to avoid coupling this identity check to the
     * sanctions-specific service.
     */
    private function nameMatchScore(string $identityName, string $accountName): int
    {
        $a = SanctionsEntry::normalizeName($identityName);
        $b = SanctionsEntry::normalizeName($accountName);

        if ($a === '' || $b === '') {
            return 0;
        }

        if ($a === $b) {
            return 100;
        }

        similar_text($a, $b, $similarPct);

        $maxLen = max(strlen($a), strlen($b));
        $lev    = levenshtein($a, $b);
        $levPct = (1 - $lev / $maxLen) * 100;

        // Token sort handles reordering, e.g. bank "SURNAME FIRSTNAME" vs
        // KYC "Firstname Surname" — weighted most heavily since name-order
        // differences are the single most common legitimate mismatch
        // pattern between bank records and KYC data.
        $aTokens = explode(' ', $a);
        $bTokens = explode(' ', $b);
        sort($aTokens);
        sort($bTokens);
        similar_text(implode(' ', $aTokens), implode(' ', $bTokens), $tokenPct);

        return (int) round(($similarPct * 0.15) + ($levPct * 0.15) + ($tokenPct * 0.70));
    }
}
