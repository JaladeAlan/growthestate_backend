<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\LandPriceHistory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLand;
use App\Models\Withdrawal;
use App\Mail\TransactionPinResetMail;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class UserController extends Controller
{
    public function getUserUnitsForLand(Request $request, $landId)
    {
        $userLand = DB::table('user_land')
            ->where('user_id', $request->user()->id)
            ->where('land_id', $landId)
            ->first();

        return response()->json([
            'land_id'     => $landId,
            'units_owned' => $userLand ? $userLand->units : 0,
        ]);
    }

    public function getAllUserLands(Request $request)
    {
        $user = $request->user();

        $userLands = $user->userLands()
            ->with('land')
            ->where('units', '>', 0)
            ->get();

        if ($userLands->isEmpty()) {
            return response()->json(['owned_lands' => []]);
        }

        $landIds = $userLands->pluck('land_id')->toArray();
        $prices  = LandPriceHistory::currentPricesForLands($landIds);

        $ownedLands = $userLands->map(function ($userLand) use ($prices) {
            $price        = $prices->get($userLand->land_id);
            $pricePerUnit = $price ? $price->price_per_unit_kobo : 0;

            return [
                'land_id'              => $userLand->land->id,
                'land_name'            => $userLand->land->title,
                'units_owned'          => $userLand->units,
                'price_per_unit_kobo'  => $pricePerUnit,
                'price_per_unit_naira' => $pricePerUnit / 100,
                'current_value'        => ($userLand->units * $pricePerUnit) / 100,
            ];
        });

        return response()->json(['owned_lands' => $ownedLands]);
    }

    public function setTransactionPin(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'pin'              => 'required|digits:4',
            'pin_confirmation' => 'required|same:pin',
        ]);

        if ($user->transaction_pin) {
            return response()->json(['error' => 'Transaction PIN is already set. Use update instead.'], 400);
        }

        $user->transaction_pin = Hash::make($request->pin);
        $user->save();

        return response()->json(['message' => 'Transaction PIN set successfully.']);
    }

    public function updateTransactionPin(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'old_pin'              => 'required|digits:4',
            'new_pin'              => 'required|digits:4',
            'new_pin_confirmation' => 'required|same:new_pin',
        ]);

        if (! Hash::check($request->old_pin, $user->transaction_pin)) {
            return response()->json(['error' => 'Old PIN is incorrect.'], 400);
        }

        $user->transaction_pin = Hash::make($request->new_pin);
        $user->save();

        return response()->json(['message' => 'Transaction PIN updated successfully.']);
    }

    public function sendPinResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $key = 'pin-reset-send:' . sha1(strtolower(trim($request->email)) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'message'     => 'Too many attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 900); // 15-minute decay

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $code = random_int(100000, 999999);
            $user->pin_reset_code       = Hash::make((string) $code);
            $user->pin_reset_expires_at = now()->addMinutes(10);
            $user->save();

            try {
                Mail::to($user->email)->queue(new TransactionPinResetMail($user, $code));
            } catch (\Exception $e) {
                Log::error('Failed to queue PIN reset email', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Always return the same response to prevent enumeration
        return response()->json(['message' => 'If that email is registered, a PIN reset code has been sent.']);
    }

    public function verifyPinResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email', 'code' => 'required|numeric']);

        $key = 'pin-reset-verify:' . sha1(strtolower(trim($request->email)) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message'     => 'Too many attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        if (
            ! $user ||
            ! $user->pin_reset_code ||
            ! $user->pin_reset_expires_at ||
            now()->greaterThan($user->pin_reset_expires_at) ||
            ! Hash::check((string) $request->code, $user->pin_reset_code)
        ) {
            RateLimiter::hit($key, 900);
            return response()->json(['error' => 'Invalid or expired code.'], 400);
        }

        RateLimiter::clear($key);
        return response()->json(['message' => 'Code verified.']);
    }

    /**
     * Reset transaction PIN — atomically re-validates the code and expiry
     * in a single DB transaction, preventing TOCTOU race conditions.
     */
    public function resetTransactionPin(Request $request)
    {
        $request->validate([
            'email'                => 'required|email',
            'code'                 => 'required|numeric',
            'new_pin'              => 'required|digits:4',
            'new_pin_confirmation' => 'required|same:new_pin',
        ]);

        $key = 'pin-reset-confirm:' . sha1(strtolower(trim($request->email)) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message'     => 'Too many attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        DB::transaction(function () use ($request, $key) {
            // Lock the user row so concurrent resets queue behind this one
            $user = User::where('email', $request->email)
                ->lockForUpdate()
                ->first();

            if (
                ! $user ||
                ! $user->pin_reset_code ||
                ! $user->pin_reset_expires_at ||
                now()->greaterThan($user->pin_reset_expires_at) ||
                ! Hash::check((string) $request->code, $user->pin_reset_code)
            ) {
                RateLimiter::hit($key, 900);
                abort(400, 'Invalid or expired code.');
            }

            $user->transaction_pin      = Hash::make($request->new_pin);
            $user->pin_reset_code       = null;
            $user->pin_reset_expires_at = null;
            $user->save();

            RateLimiter::clear($key);
        });

        return response()->json(['message' => 'Transaction PIN reset successfully.']);
    }

    public function updateBankDetails(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'account_number' => 'required|numeric|digits_between:10,12',
            'bank_name'      => 'required|string',
        ]);

        try {
            $banks = $this->getPaystackBanks();
            $bank  = collect($banks)->firstWhere('name', $request->bank_name);

            if (! $bank) {
                return response()->json(['error' => 'Invalid bank name.'], 400);
            }

            $bankCode = $bank['code'];

            $resolveResponse = Http::withToken(config('services.paystack.secret_key'))
                ->get('https://api.paystack.co/bank/resolve', [
                    'account_number' => $request->account_number,
                    'bank_code'      => $bankCode,
                ]);

            if (! $resolveResponse->successful()) {
                return response()->json(['error' => 'Failed to verify account number.'], 400);
            }

            $accountName = $resolveResponse->json()['data']['account_name'] ?? null;
            if (! $accountName) {
                return response()->json(['error' => 'Invalid account details.'], 400);
            }

            $user->update([
                'account_number' => $request->account_number,
                'bank_code'      => $bankCode,
                'bank_name'      => $request->bank_name,
                'account_name'   => $accountName,
                'recipient_code' => null,
            ]);

            return response()->json([
                'message' => 'Bank details updated successfully.',
                'data'    => [
                    'bank_name'      => $request->bank_name,
                    'bank_code'      => $bankCode,
                    'account_number' => $request->account_number,
                    'account_name'   => $accountName,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Bank update error', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function getBanks()
    {
        try {
            return response()->json(['data' => $this->getPaystackBanks()]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to fetch bank list.'], 500);
        }
    }

    private function getPaystackBanks(): array
    {
        return Cache::remember('paystack_banks', now()->addHours(12), function () {
            $response = Http::withToken(config('services.paystack.secret_key'))
                ->get('https://api.paystack.co/bank');
            if (! $response->successful()) {
                throw new \Exception('Unable to fetch bank list.');
            }
            return $response->json()['data'];
        });
    }

    public function resolveAccount(Request $request)
    {
        $request->validate([
            'account_number' => 'required|digits:10',
            'bank_code'      => 'required|string',
        ]);

        try {
            $response = Http::withToken(config('services.paystack.secret_key'))
                ->get('https://api.paystack.co/bank/resolve', [
                    'account_number' => $request->account_number,
                    'bank_code'      => $request->bank_code,
                ]);

            if (! $response->successful()) {
                return response()->json(['error' => 'Verification failed.'], 400);
            }

            return response()->json($response->json());

        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred.'], 500);
        }
    }

    public function getUserStats(Request $request)
    {
        $user = $request->user();

        $landsOwned = DB::table('user_land')
            ->where('user_id', $user->id)->where('units', '>', 0)
            ->distinct('land_id')->count('land_id');

        $unitsOwned = DB::table('user_land')
            ->where('user_id', $user->id)->sum('units');

        $investedData = DB::table('purchases')
            ->select(DB::raw('SUM(total_amount_paid_kobo) as total_paid, SUM(total_amount_received_kobo) as total_received'))
            ->where('user_id', $user->id)
            ->whereIn('status', ['completed', 'partially_sold'])
            ->first();

        $totalInvestedKobo = ($investedData->total_paid ?? 0) - ($investedData->total_received ?? 0);

        $totalWithdrawn = DB::table('withdrawals')
            ->where('user_id', $user->id)->where('status', 'completed')
            ->sum('amount_kobo');

        $pendingWithdrawals = DB::table('withdrawals')
            ->where('user_id', $user->id)->where('status', 'pending')->count();

        $totalRewardsClaimed = DB::table('referral_rewards')
            ->where('user_id', $user->id)->where('claimed', true)
            ->sum('amount_kobo');

        return response()->json([
            'success' => true,
            'data'    => [
                'lands_owned'                     => $landsOwned,
                'units_owned'                     => $unitsOwned,
                'total_invested'                  => $totalInvestedKobo / 100,
                'total_invested_kobo'             => $totalInvestedKobo,
                'total_withdrawn'                 => $totalWithdrawn / 100,
                'total_withdrawn_kobo'            => $totalWithdrawn,
                'pending_withdrawals'             => $pendingWithdrawals,
                'balance'                         => $user->balance_kobo / 100,
                'balance_kobo'                    => $user->balance_kobo,
                'rewards_balance'                 => $user->rewards_balance_kobo / 100,
                'rewards_balance_kobo'            => $user->rewards_balance_kobo,
                'total_spendable'                 => $user->total_spendable_kobo / 100,
                'total_spendable_kobo'            => $user->total_spendable_kobo,
                'total_rewards_claimed'           => $totalRewardsClaimed / 100,
                'total_rewards_claimed_kobo'      => $totalRewardsClaimed,
                'withdrawal_daily_limit'          => $this->dailyLimitKobo() / 100,
                'withdrawal_daily_used_kobo'      => $user->withdrawal_day === now()->toDateString()
                    ? $user->withdrawal_daily_total_kobo : 0,
                'withdrawal_daily_remaining_kobo' => $this->dailyRemainingKobo($user),
            ],
        ]);
    }

    private function dailyLimitKobo(): int
    {
        return (int) config('services.withdrawals.daily_limit_kobo', 50_000_000);
    }

    private function dailyRemainingKobo($user): int
    {
        $used = $user->withdrawal_day === now()->toDateString()
            ? $user->withdrawal_daily_total_kobo
            : 0;
        return max(0, $this->dailyLimitKobo() - $used);
    }

    public function getUserTransactions(Request $request)
    {
        $user = $request->user();

        $deposits = Deposit::where('user_id', $user->id)
            ->select('id', 'amount_kobo', 'transaction_fee', 'total_kobo', 'status', 'created_at')
            ->get()->map(fn ($d) => [
                'type'            => 'Deposit',
                'amount'          => $d->amount_kobo / 100,
                'transaction_fee' => ($d->transaction_fee ?? 0) / 100,
                'total'           => ($d->total_kobo ?? $d->amount_kobo) / 100,
                'status'          => ucfirst($d->status),
                'date'            => $d->created_at->toIso8601String(),
            ]);

        $withdrawals = Withdrawal::where('user_id', $user->id)
            ->select('id', 'amount_kobo', 'status', 'created_at')
            ->get()->map(fn ($w) => [
                'type'   => 'Withdrawal',
                'amount' => $w->amount_kobo / 100,
                'status' => ucfirst($w->status),
                'date'   => $w->created_at->toIso8601String(),
            ]);

        $landTransactions = Transaction::where('user_id', $user->id)
            ->select('id', 'land_id', 'type', 'units', 'amount_kobo', 'status', 'created_at')
            ->with('land:id,title')
            ->get()->map(fn ($t) => [
                'type'   => ucfirst($t->type),
                'land'   => $t->land->title ?? 'Unknown',
                'amount' => abs($t->amount_kobo) / 100,
                'units'  => abs($t->units),
                'status' => ucfirst($t->status),
                'date'   => $t->created_at->toIso8601String(),
            ]);

        $transactions = collect()
            ->merge($deposits)->merge($withdrawals)->merge($landTransactions)
            ->sortByDesc('date')->values();

        return response()->json(['success' => true, 'data' => $transactions]);
    }

    public function getPortfolioSummary(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => PortfolioService::summary($request->user()->id),
        ]);
    }
}