<?php

namespace App\Services;

use App\Models\LedgerLine;
use App\Models\LedgerTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only authorised path for writing to the double-entry ledger.
 *
 * Every public method on this service wraps a complete, balanced
 * transaction: it asserts the two lines net to zero before committing
 * and throws if the idempotency key already exists (duplicate event).
 *
 * Callers must already hold a lockForUpdate() on the relevant User row
 * (and the Deposit/Withdrawal row where applicable) before calling any
 * method here. LedgerService does not acquire locks itself — it is the
 * innermost layer of a larger DB::transaction().
 *
 * Account key format:
 *   "user:{id}:main"     — user's primary (kobo) wallet
 *   "user:{id}:rewards"  — user's rewards wallet
 *   "platform:float"     — money the platform holds on behalf of users
 *   "platform:fees"      — fee revenue collected by the platform
 */
class LedgerService
{
    /**
     * Post a successful deposit.
     *
     * Money flow:
     *   platform:float  → debit  (gateway collected it; we release it)
     *   user:{id}:main  → credit (user's wallet grows)
     *
     * @param  User   $user         Already locked with lockForUpdate().
     * @param  int    $amountKobo   Principal credited to the user (>0).
     * @param  string $reference    Gateway reference (e.g. Paystack ref).
     * @param  int    $feeKobo      Gateway fee retained by platform (≥0).
     * @param  string $gateway      'paystack' | 'monnify' | 'opay'
     * @return LedgerTransaction
     */
    public static function postDeposit(
        User   $user,
        int    $amountKobo,
        string $reference,
        int    $feeKobo = 0,
        string $gateway = '',
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Deposit amount_kobo must be positive; got {$amountKobo}.");
        }

        $note = $gateway ? "Deposit via {$gateway}" : 'Deposit';

        // post() handles idempotency, balance assertion, and immutable writes
        $tx = self::post(
            type:           'deposit',
            idempotencyKey: "deposit:{$reference}",
            reference:      $reference,
            note:           $note,
            debitAccount:   'platform:float',
            creditAccount:  "user:{$user->id}:main",
            amountKobo:     $amountKobo,
            balanceAfter:   (int) $user->balance_kobo,
        );

        // Fee line — platform earns the gateway fee
        if ($feeKobo > 0) {
            self::post(
                type:           'transaction_fee',
                idempotencyKey: "transaction_fee:{$reference}",
                reference:      $reference,
                note:           'Gateway fee',
                debitAccount:   'platform:float',
                creditAccount:  'platform:fees',
                amountKobo:     $feeKobo,
                balanceAfter:   null,   // platform accounts have no cached balance
            );
        }

        return $tx;
    }

    /**
     * Post a land purchase (main wallet debited).
     *
     * Money flow:
     *   user:{id}:main  → debit  (user pays from their wallet)
     *   platform:float  → credit (platform holds the funds)
     *
     * Note: the rewards portion of a purchase is recorded separately by
     * RewardsService::spend() until that flow migrates to double-entry.
     * Only call this when $mainUsed > 0.
     *
     * @param  User   $user         Already locked with lockForUpdate().
     * @param  int    $amountKobo   Amount paid from main wallet (>0).
     * @param  string $reference    Purchase reference (PUR-{uuid}).
     * @param  string $note         Optional label (e.g. land title).
     * @return LedgerTransaction
     */
    public static function postPurchase(
        User   $user,
        int    $amountKobo,
        string $reference,
        string $note = '',
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Purchase amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::post(
            type:           'purchase',
            idempotencyKey: "purchase:{$reference}",
            reference:      $reference,
            note:           $note ?: 'Land unit purchase',
            debitAccount:   "user:{$user->id}:main",
            creditAccount:  'platform:float',
            amountKobo:     $amountKobo,
            balanceAfter:   (int) $user->balance_kobo,
        );
    }

    /**
     * Post a land sale (main wallet credited).
     *
     * Money flow:
     *   platform:float  → debit  (platform releases the proceeds)
     *   user:{id}:main  → credit (user's balance grows)
     *
     * @param  User   $user         Already locked with lockForUpdate().
     * @param  int    $amountKobo   Proceeds credited to user (>0).
     * @param  string $reference    Sale reference (SALE-{uuid}).
     * @param  string $note         Optional label (e.g. land title).
     * @return LedgerTransaction
     */
    public static function postSale(
        User   $user,
        int    $amountKobo,
        string $reference,
        string $note = '',
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Sale amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::post(
            type:           'sale',
            idempotencyKey: "sale:{$reference}",
            reference:      $reference,
            note:           $note ?: 'Land unit sale proceeds',
            debitAccount:   'platform:float',
            creditAccount:  "user:{$user->id}:main",
            amountKobo:     $amountKobo,
            balanceAfter:   (int) $user->balance_kobo,
        );
    }
     /**
     * Money flow:
     *   user:{id}:main  → debit  (balance drops immediately)
     *   platform:float  → credit (platform holds the funds pending payout)
     *
     * @param  User   $user         Already locked with lockForUpdate().
     * @param  int    $amountKobo   Amount being withdrawn (>0).
     * @param  string $reference    Withdrawal reference code.
     * @return LedgerTransaction
     */
    public static function postWithdrawal(
        User   $user,
        int    $amountKobo,
        string $reference,
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Withdrawal amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::post(
            type:           'withdrawal',
            idempotencyKey: "withdrawal:{$reference}",
            reference:      $reference,
            note:           'Withdrawal request — funds held pending payout',
            debitAccount:   "user:{$user->id}:main",
            creditAccount:  'platform:float',
            amountKobo:     $amountKobo,
            balanceAfter:   (int) $user->balance_kobo,
        );
    }

    /**
     * Post a withdrawal reversal (failed/rejected — funds returned to user).
     *
     * Money flow:
     *   platform:float  → debit  (platform releases the held funds)
     *   user:{id}:main  → credit (user's balance is restored)
     *
     * @param  User   $user         Already locked with lockForUpdate().
     * @param  int    $amountKobo   Amount being returned (>0).
     * @param  string $reference    Original withdrawal reference.
     * @param  string $reason       Human-readable reason for the reversal.
     * @return LedgerTransaction
     */
    public static function postWithdrawalReversal(
        User   $user,
        int    $amountKobo,
        string $reference,
        string $reason = '',
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Withdrawal reversal amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::post(
            type:           'withdrawal_reversal',
            idempotencyKey: "withdrawal_reversal:{$reference}",
            reference:      $reference,
            note:           $reason ?: 'Withdrawal reversed — funds returned to user',
            debitAccount:   'platform:float',
            creditAccount:  "user:{$user->id}:main",
            amountKobo:     $amountKobo,
            balanceAfter:   (int) $user->balance_kobo,
        );
    }

    /**
     * Post a withdrawal completion (funds settled to the user's bank).
     *
     * No balance change — user's balance was already debited on request.
     * This records the moment the platform's float is reduced by the
     * payout: platform:float → platform:payouts.
     *
     * Money flow:
     *   platform:float    → debit  (funds leave the platform pool)
     *   platform:payouts  → credit (settled to external bank)
     *
     * @param  int    $amountKobo   Amount settled (>0).
     * @param  string $reference    Original withdrawal reference.
     * @return LedgerTransaction
     */
    public static function postWithdrawalCompleted(
        int    $amountKobo,
        string $reference,
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Withdrawal completion amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::post(
            type:           'withdrawal_completed',
            idempotencyKey: "withdrawal_completed:{$reference}",
            reference:      $reference,
            note:           'Withdrawal settled to user bank account',
            debitAccount:   'platform:float',
            creditAccount:  'platform:payouts',
            amountKobo:     $amountKobo,
            balanceAfter:   null,
        );
    }

    /**
     * Post a rewards credit (user earns rewards).
     *
     * Money flow:
     *   platform:rewards_pool  → debit  (platform issues the reward)
     *   user:{id}:rewards      → credit (user's rewards wallet grows)
     *
     * @param  User   $user              Already locked with lockForUpdate().
     * @param  int    $amountKobo        Amount credited to rewards (>0).
     * @param  string $reference         Unique reference (e.g. REF-REWARD-{id}).
     * @param  string $note              Human-readable reason.
     * @param  int    $rewardsBalanceAfter  rewards_balance_kobo after increment.
     * @return LedgerTransaction
     */
    public static function postRewardCredit(
        User   $user,
        int    $amountKobo,
        string $reference,
        string $note = '',
        int    $rewardsBalanceAfter = 0,
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Reward credit amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::postLines(
            type:           'reward_credit',
            idempotencyKey: "reward_credit:{$reference}",
            reference:      $reference,
            note:           $note ?: 'Reward credit',
            lines: [
                [
                    'account'      => 'platform:rewards_pool',
                    'amount_kobo'  => -$amountKobo,
                    'balance_after' => null,
                ],
                [
                    'account'      => "user:{$user->id}:rewards",
                    'amount_kobo'  => $amountKobo,
                    'balance_after' => $rewardsBalanceAfter,
                ],
            ],
        );
    }

    /**
     * Post a rewards spend (user applies rewards to a purchase).
     *
     * Money flow:
     *   user:{id}:rewards  → debit  (rewards consumed)
     *   platform:float     → credit (offsets the cost platform receives)
     *
     * @param  User   $user              Already locked with lockForUpdate().
     * @param  int    $amountKobo        Amount spent from rewards (>0).
     * @param  string $reference         Purchase reference.
     * @param  string $note
     * @param  int    $rewardsBalanceAfter  rewards_balance_kobo after decrement.
     * @return LedgerTransaction
     */
    public static function postRewardSpend(
        User   $user,
        int    $amountKobo,
        string $reference,
        string $note = '',
        int    $rewardsBalanceAfter = 0,
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Reward spend amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::postLines(
            type:           'reward_spend',
            idempotencyKey: "reward_spend:{$reference}",
            reference:      $reference,
            note:           $note ?: 'Reward spend',
            lines: [
                [
                    'account'      => "user:{$user->id}:rewards",
                    'amount_kobo'  => -$amountKobo,
                    'balance_after' => $rewardsBalanceAfter,
                ],
                [
                    'account'      => 'platform:float',
                    'amount_kobo'  => $amountKobo,
                    'balance_after' => null,
                ],
            ],
        );
    }

    /**
     * Post a rewards reversal (reward revoked — rewards balance reduced).
     *
     * Money flow:
     *   user:{id}:rewards      → debit  (rewards clawed back)
     *   platform:rewards_pool  → credit (returned to platform pool)
     *
     * @param  User   $user              Already locked with lockForUpdate().
     * @param  int    $amountKobo        Amount reversed (>0).
     * @param  string $reference         Original reward reference.
     * @param  string $note
     * @param  int    $rewardsBalanceAfter  rewards_balance_kobo after decrement.
     * @return LedgerTransaction
     */
    public static function postRewardReversal(
        User   $user,
        int    $amountKobo,
        string $reference,
        string $note = '',
        int    $rewardsBalanceAfter = 0,
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("Reward reversal amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::postLines(
            type:           'reward_reversal',
            idempotencyKey: "reward_reversal:{$reference}",
            reference:      $reference,
            note:           $note ?: 'Reward reversal',
            lines: [
                [
                    'account'      => "user:{$user->id}:rewards",
                    'amount_kobo'  => -$amountKobo,
                    'balance_after' => $rewardsBalanceAfter,
                ],
                [
                    'account'      => 'platform:rewards_pool',
                    'amount_kobo'  => $amountKobo,
                    'balance_after' => null,
                ],
            ],
        );
    }

    /**
     * Money flow (single balanced transaction):
     *   user:{buyer_id}:main   → debit  $totalKobo
     *   user:{seller_id}:main  → credit $sellerGets  ($totalKobo - $feeKobo)
     *   platform:fees          → credit $feeKobo
     *
     * Net: -totalKobo + sellerGets + feeKobo = 0 ✓
     *
     * @param  User   $buyer        Already locked with lockForUpdate().
     * @param  User   $seller       Already locked with lockForUpdate().
     * @param  int    $totalKobo    Total buyer pays (>0).
     * @param  int    $feeKobo      Platform fee retained (≥0).
     * @param  string $reference    Trade reference (MKT-{uuid}).
     * @return LedgerTransaction
     */
    public static function postMarketplaceTrade(
        \App\Models\User $buyer,
        \App\Models\User $seller,
        int    $totalKobo,
        int    $feeKobo,
        string $reference,
    ): LedgerTransaction {
        if ($totalKobo <= 0) {
            throw new RuntimeException("Marketplace trade totalKobo must be positive; got {$totalKobo}.");
        }
        if ($feeKobo < 0) {
            throw new RuntimeException("Marketplace trade feeKobo cannot be negative; got {$feeKobo}.");
        }

        $sellerGets = $totalKobo - $feeKobo;

        return self::postLines(
            type:           'marketplace_trade',
            idempotencyKey: "marketplace_trade:{$reference}",
            reference:      $reference,
            note:           "Peer trade: buyer {$buyer->id} → seller {$seller->id}",
            lines: [
                [
                    'account'      => "user:{$buyer->id}:main",
                    'amount_kobo'  => -$totalKobo,
                    'balance_after' => (int) $buyer->balance_kobo,
                ],
                [
                    'account'      => "user:{$seller->id}:main",
                    'amount_kobo'  => $sellerGets,
                    'balance_after' => (int) $seller->balance_kobo,
                ],
                [
                    'account'      => 'platform:fees',
                    'amount_kobo'  => $feeKobo,
                    'balance_after' => null,
                ],
            ],
        );
    }

    /**
     * Write one balanced multi-line transaction to the ledger.
     *
     * $lines is an array of ['account', 'amount_kobo' (signed), 'balance_after' (?int)].
     * The sum of all amount_kobo values must equal zero — this is asserted
     * before any rows are written and again after (belt-and-suspenders).
     *
     * Throws if:
     *  - the idempotency key already exists (duplicate event)
     *  - the lines do not net to zero
     *
     * Must be called inside an existing DB::transaction().
     *
     * @param  array<array{account: string, amount_kobo: int, balance_after: int|null}> $lines
     */
    private static function postLines(
        string $type,
        string $idempotencyKey,
        string $reference,
        string $note,
        array  $lines,
    ): LedgerTransaction {
        // Pre-flight: lines must net to zero before touching the DB.
        $net = array_sum(array_column($lines, 'amount_kobo'));
        if ($net !== 0) {
            throw new RuntimeException(
                "LedgerService::postLines lines are unbalanced before insert: net={$net} kobo."
            );
        }

        if (LedgerTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
            throw new RuntimeException(
                "Duplicate ledger idempotency key: {$idempotencyKey}. Event already posted."
            );
        }

        $tx = LedgerTransaction::create([
            'type'            => $type,
            'idempotency_key' => $idempotencyKey,
            'reference'       => $reference,
            'note'            => $note,
        ]);

        foreach ($lines as $line) {
            LedgerLine::create([
                'ledger_transaction_id' => $tx->id,
                'account'               => $line['account'],
                'amount_kobo'           => $line['amount_kobo'],
                'balance_after'         => $line['balance_after'] ?? null,
            ]);
        }

        // Post-flight DB assertion (catches any ORM rounding surprises).
        $dbNet = $tx->lines()->sum('amount_kobo');
        if ($dbNet !== 0) {
            throw new RuntimeException(
                "Ledger transaction {$tx->id} is unbalanced after insert: net={$dbNet} kobo. Rolling back."
            );
        }

        return $tx;
    }

    /**
     * Convenience wrapper for the common 2-line (one debit, one credit) case.
     * amount_kobo must be positive; sign is applied internally.
     */
    private static function post(
        string  $type,
        string  $idempotencyKey,
        string  $reference,
        string  $note,
        string  $debitAccount,
        string  $creditAccount,
        int     $amountKobo,
        ?int    $balanceAfter,
    ): LedgerTransaction {
        if ($amountKobo <= 0) {
            throw new RuntimeException("LedgerService::post amount_kobo must be positive; got {$amountKobo}.");
        }

        return self::postLines(
            type:           $type,
            idempotencyKey: $idempotencyKey,
            reference:      $reference,
            note:           $note,
            lines: [
                ['account' => $debitAccount,  'amount_kobo' => -$amountKobo, 'balance_after' => null],
                ['account' => $creditAccount, 'amount_kobo' => $amountKobo,  'balance_after' => $balanceAfter],
            ],
        );
    }
}