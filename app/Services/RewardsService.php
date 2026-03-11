<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RewardsService
 *
 * Central place for all rewards balance operations so the logic is never
 * scattered across multiple controllers.
 *
 * Rules enforced here:
 *  - Rewards are NOT withdrawable (spendable on-platform only).
 *  - Every movement is recorded in the ledger with type=reward_credit/reward_spend.
 *  - All writes lock the user row to prevent race conditions.
 */
class RewardsService
{
    /**
     * Credit rewards to a user's rewards wallet.
     *
     * @param  User    $user
     * @param  int     $amountKobo
     * @param  string  $reference   Unique reference for the ledger entry
     * @param  string  $note        Human-readable reason
     * @return void
     */
    public static function credit(User $user, int $amountKobo, string $reference, string $note = ''): void
    {
        if ($amountKobo <= 0) {
            Log::warning('RewardsService::credit called with non-positive amount', [
                'user_id'     => $user->id,
                'amount_kobo' => $amountKobo,
                'reference'   => $reference,
            ]);
            return;
        }

        DB::transaction(function () use ($user, $amountKobo, $reference, $note) {
            $locked = User::lockForUpdate()->find($user->id);
            $locked->increment('rewards_balance_kobo', $amountKobo);
            $rewardsAfter = $locked->fresh()->rewards_balance_kobo;

            LedgerEntry::create([
                'uid'                   => $locked->id,
                'type'                  => 'reward_credit',
                'amount_kobo'           => $amountKobo,
                'balance_after'         => $locked->balance_kobo,  // main wallet unchanged
                'rewards_balance_after' => $rewardsAfter,
                'reference'             => $reference,
            ]);

            Log::info('Rewards credited', [
                'user_id'              => $locked->id,
                'amount_kobo'          => $amountKobo,
                'rewards_balance_after' => $rewardsAfter,
                'reference'            => $reference,
                'note'                 => $note,
            ]);
        });
    }

    /**
     * Spend from the rewards wallet.
     * Returns the actual amount spent (may be less than requested if balance is low).
     *
     * @param  User    $user
     * @param  int     $requestedKobo  How much to spend (capped at rewards balance)
     * @param  string  $reference
     * @param  string  $note
     * @return int  Amount actually spent from rewards
     */
    public static function spend(User $user, int $requestedKobo, string $reference, string $note = ''): int
    {
        if ($requestedKobo <= 0) {
            return 0;
        }

        $actualSpend = 0;

        DB::transaction(function () use ($user, $requestedKobo, $reference, $note, &$actualSpend) {
            $locked      = User::lockForUpdate()->find($user->id);
            $actualSpend = min($locked->rewards_balance_kobo, $requestedKobo);

            if ($actualSpend <= 0) {
                return;
            }

            $locked->decrement('rewards_balance_kobo', $actualSpend);
            $rewardsAfter = $locked->fresh()->rewards_balance_kobo;

            LedgerEntry::create([
                'uid'                   => $locked->id,
                'type'                  => 'reward_spend',
                'amount_kobo'           => $actualSpend,
                'balance_after'         => $locked->balance_kobo,  // main wallet unchanged
                'rewards_balance_after' => $rewardsAfter,
                'reference'             => $reference,
            ]);

            Log::info('Rewards spent', [
                'user_id'              => $locked->id,
                'requested_kobo'       => $requestedKobo,
                'actual_spend_kobo'    => $actualSpend,
                'rewards_balance_after' => $rewardsAfter,
                'reference'            => $reference,
                'note'                 => $note,
            ]);
        });

        return $actualSpend;
    }

    /**
     * Reverse a rewards credit (e.g. reward revoked by admin, fraud detection).
     * Only reverses up to the current rewards balance — will not go negative.
     */
    public static function reverseCredit(User $user, int $amountKobo, string $reference, string $note = ''): void
    {
        DB::transaction(function () use ($user, $amountKobo, $reference, $note) {
            $locked      = User::lockForUpdate()->find($user->id);
            $actualDebit = min($locked->rewards_balance_kobo, $amountKobo);

            if ($actualDebit <= 0) {
                Log::warning('RewardsService::reverseCredit — nothing to reverse', [
                    'user_id'              => $locked->id,
                    'requested_reversal'   => $amountKobo,
                    'rewards_balance_kobo' => $locked->rewards_balance_kobo,
                ]);
                return;
            }

            $locked->decrement('rewards_balance_kobo', $actualDebit);
            $rewardsAfter = $locked->fresh()->rewards_balance_kobo;

            LedgerEntry::create([
                'uid'                   => $locked->id,
                'type'                  => 'reward_spend',   // debit from rewards
                'amount_kobo'           => $actualDebit,
                'balance_after'         => $locked->balance_kobo,
                'rewards_balance_after' => $rewardsAfter,
                'reference'             => $reference . '-REVERSAL',
            ]);

            Log::info('Rewards credit reversed', [
                'user_id'       => $locked->id,
                'reversed_kobo' => $actualDebit,
                'reference'     => $reference,
                'note'          => $note,
            ]);
        });
    }

    /**
     * Get a summary of a user's rewards activity.
     */
    public static function summary(User $user): array
    {
        $ledger = LedgerEntry::where('uid', $user->id)
            ->whereIn('type', ['reward_credit', 'reward_spend'])
            ->get();

        $totalEarned = $ledger->where('type', 'reward_credit')->sum('amount_kobo');
        $totalSpent  = $ledger->where('type', 'reward_spend')->sum('amount_kobo');

        $unclaimedRewards = ReferralReward::where('user_id', $user->id)
            ->where('claimed', false)
            ->whereNotNull('amount_kobo')
            ->sum('amount_kobo');

        return [
            'rewards_balance_kobo'   => $user->rewards_balance_kobo,
            'rewards_balance_naira'  => $user->rewards_balance_kobo / 100,
            'total_earned_kobo'      => $totalEarned,
            'total_earned_naira'     => $totalEarned / 100,
            'total_spent_kobo'       => $totalSpent,
            'total_spent_naira'      => $totalSpent / 100,
            'unclaimed_rewards_kobo' => $unclaimedRewards,
            'unclaimed_rewards_naira' => $unclaimedRewards / 100,
        ];
    }
}
