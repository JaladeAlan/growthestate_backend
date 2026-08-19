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
 *  - Every movement is recorded via LedgerService (double-entry).
 *  - All writes lock the user row to prevent race conditions.
 *
 * Live call sites:
 *  - credit()        — was called externally; now dead (ReferralController
 *                      calls LedgerService::postRewardCredit directly).
 *  - spend()         — was called from PurchaseController; now dead.
 *  - reverseCredit() — admin-only; no active caller yet. Retained for
 *                      future use; updated to use LedgerService.
 *
 * @deprecated credit() and spend() are no longer called externally. They
 *             are retained temporarily to avoid breaking any test stubs,
 *             but can be removed once tests are updated.
 */
class RewardsService
{
    /**
     * @deprecated Direct callers should use LedgerService::postRewardCredit().
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

            LedgerService::postRewardCredit(
                user:                $locked->fresh(),
                amountKobo:          $amountKobo,
                reference:           $reference,
                note:                $note,
                rewardsBalanceAfter: $rewardsAfter,
            );

            Log::info('Rewards credited', [
                'user_id'               => $locked->id,
                'amount_kobo'           => $amountKobo,
                'rewards_balance_after' => $rewardsAfter,
                'reference'             => $reference,
                'note'                  => $note,
            ]);
        });
    }

    /**
     * @deprecated Direct callers should use LedgerService::postRewardSpend().
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

            LedgerService::postRewardSpend(
                user:                $locked->fresh(),
                amountKobo:          $actualSpend,
                reference:           $reference,
                note:                $note,
                rewardsBalanceAfter: $rewardsAfter,
            );

            Log::info('Rewards spent', [
                'user_id'               => $locked->id,
                'requested_kobo'        => $requestedKobo,
                'actual_spend_kobo'     => $actualSpend,
                'rewards_balance_after' => $rewardsAfter,
                'reference'             => $reference,
                'note'                  => $note,
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

            LedgerService::postRewardReversal(
                user:                $locked->fresh(),
                amountKobo:          $actualDebit,
                reference:           $reference,
                note:                $note ?: 'Reward credit reversed',
                rewardsBalanceAfter: $rewardsAfter,
            );

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
     * Reads from ledger_lines for double-entry flows, falls back to
     * ledger_entries for any historical rows pre-migration.
     */
    public static function summary(User $user): array
    {
        // New double-entry rows
        $earned = \App\Models\LedgerLine::where('account', "user:{$user->id}:rewards")
            ->where('amount_kobo', '>', 0)
            ->sum('amount_kobo');

        $spent = abs(\App\Models\LedgerLine::where('account', "user:{$user->id}:rewards")
            ->where('amount_kobo', '<', 0)
            ->sum('amount_kobo'));

        // Historical single-entry rows (pre-migration)
        $legacyLedger = LedgerEntry::where('uid', $user->id)
            ->whereIn('type', ['reward_credit', 'reward_spend'])
            ->get();

        $earned += $legacyLedger->where('type', 'reward_credit')->sum('amount_kobo');
        $spent  += $legacyLedger->where('type', 'reward_spend')->sum('amount_kobo');

        $unclaimedRewards = ReferralReward::where('user_id', $user->id)
            ->where('claimed', false)
            ->whereNotNull('amount_kobo')
            ->sum('amount_kobo');

        return [
            'rewards_balance_kobo'    => $user->rewards_balance_kobo,
            'rewards_balance_naira'   => $user->rewards_balance_kobo / 100,
            'total_earned_kobo'       => $earned,
            'total_earned_naira'      => $earned / 100,
            'total_spent_kobo'        => $spent,
            'total_spent_naira'       => $spent / 100,
            'unclaimed_rewards_kobo'  => $unclaimedRewards,
            'unclaimed_rewards_naira' => $unclaimedRewards / 100,
        ];
    }
}
