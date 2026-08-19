<?php

use App\Models\LedgerLine;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function ledgerUser(int $balanceKobo = 0, int $rewardsKobo = 0): User
{
    return User::factory()->create([
        'email_verified_at'    => now(),
        'balance_kobo'         => $balanceKobo,
        'rewards_balance_kobo' => $rewardsKobo,
    ]);
}

function linesFor(LedgerTransaction $tx): \Illuminate\Support\Collection
{
    return LedgerLine::where('ledger_transaction_id', $tx->id)->get();
}

// ─────────────────────────────────────────────────────────────────────────────
// Core invariants (apply to every posting method)
// ─────────────────────────────────────────────────────────────────────────────

describe('Core ledger invariants', function () {

    it('every posted transaction has lines that net to zero', function () {
        $user = ledgerUser();

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 1_000_000);
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 1_000_000,
                reference:  'DEP-invariant-001',
                gateway:    'paystack',
            );
        });

        $txs = LedgerTransaction::all();
        foreach ($txs as $tx) {
            $net = LedgerLine::where('ledger_transaction_id', $tx->id)->sum('amount_kobo');
            expect($net)->toBe(0, "Transaction {$tx->id} (type={$tx->type}) does not net to zero");
        }
    });

    it('rejects a duplicate idempotency key', function () {
        $user = ledgerUser();

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 500_000);
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 500_000,
                reference:  'DEP-dupe-001',
                gateway:    'paystack',
            );
        });

        expect(fn () => DB::transaction(function () use ($user) {
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 500_000,
                reference:  'DEP-dupe-001',   // same reference = same idempotency key
                gateway:    'paystack',
            );
        }))->toThrow(RuntimeException::class, 'Duplicate ledger idempotency key');
    });

    it('LedgerTransaction rows are immutable after creation', function () {
        $user = ledgerUser();

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 100_000);
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 100_000,
                reference:  'DEP-immut-001',
                gateway:    'paystack',
            );
        });

        $tx = LedgerTransaction::first();

        expect(fn () => $tx->update(['note' => 'tampered']))
            ->toThrow(RuntimeException::class, 'immutable');

        expect(fn () => $tx->delete())
            ->toThrow(RuntimeException::class, 'immutable');
    });

    it('LedgerLine rows are immutable after creation', function () {
        $user = ledgerUser();

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 100_000);
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 100_000,
                reference:  'DEP-line-immut-001',
                gateway:    'paystack',
            );
        });

        $line = LedgerLine::first();

        expect(fn () => $line->update(['amount_kobo' => 999]))
            ->toThrow(RuntimeException::class, 'immutable');

        expect(fn () => $line->delete())
            ->toThrow(RuntimeException::class, 'immutable');
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Deposits
// ─────────────────────────────────────────────────────────────────────────────

describe('LedgerService::postDeposit', function () {

    it('writes a deposit credit line to the user account and a debit to platform:float', function () {
        $user = ledgerUser();

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 1_000_000);
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 1_000_000,
                reference:  'DEP-001',
                gateway:    'paystack',
            );
        });

        $tx = LedgerTransaction::where('reference', 'DEP-001')->where('type', 'deposit')->first();
        expect($tx)->not->toBeNull();

        $lines = linesFor($tx);
        expect($lines)->toHaveCount(2);

        $debit  = $lines->firstWhere('account', 'platform:float');
        $credit = $lines->firstWhere('account', "user:{$user->id}:main");

        expect($debit->amount_kobo)->toBe(-1_000_000);
        expect($credit->amount_kobo)->toBe(1_000_000);
        expect($credit->balance_after)->toBe(1_000_000);
    });

    it('writes a separate fee transaction when feeKobo > 0', function () {
        $user = ledgerUser();

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 980_000);
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 980_000,
                reference:  'DEP-002',
                feeKobo:    20_000,
                gateway:    'monnify',
            );
        });

        $feeTx = LedgerTransaction::where('reference', 'DEP-002')->where('type', 'transaction_fee')->first();
        expect($feeTx)->not->toBeNull();

        $feeLines = linesFor($feeTx);
        $feeCredit = $feeLines->firstWhere('account', 'platform:fees');
        expect($feeCredit->amount_kobo)->toBe(20_000);

        // fee tx also nets to zero
        expect($feeLines->sum('amount_kobo'))->toBe(0);
    });

    it('skips the fee transaction when feeKobo is 0', function () {
        $user = ledgerUser();

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 500_000);
            LedgerService::postDeposit(
                user:       $user->fresh(),
                amountKobo: 500_000,
                reference:  'DEP-003',
                feeKobo:    0,
            );
        });

        $feeTx = LedgerTransaction::where('reference', 'DEP-003')->where('type', 'transaction_fee')->first();
        expect($feeTx)->toBeNull();
    });

    it('throws on non-positive amount', function () {
        $user = ledgerUser();
        expect(fn () => LedgerService::postDeposit(
            user:       $user,
            amountKobo: 0,
            reference:  'DEP-zero',
        ))->toThrow(RuntimeException::class);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Withdrawals
// ─────────────────────────────────────────────────────────────────────────────

describe('LedgerService::postWithdrawal / postWithdrawalReversal / postWithdrawalCompleted', function () {

    it('posts a withdrawal debit from user main to platform:float', function () {
        $user = ledgerUser(5_000_000);

        DB::transaction(function () use ($user) {
            $user->decrement('balance_kobo', 2_000_000);
            LedgerService::postWithdrawal(
                user:       $user->fresh(),
                amountKobo: 2_000_000,
                reference:  'WDL-001',
            );
        });

        $tx = LedgerTransaction::where('reference', 'WDL-001')->where('type', 'withdrawal')->first();
        expect($tx)->not->toBeNull();

        $lines  = linesFor($tx);
        $debit  = $lines->firstWhere('account', "user:{$user->id}:main");
        $credit = $lines->firstWhere('account', 'platform:float');

        expect($debit->amount_kobo)->toBe(-2_000_000);
        expect($credit->amount_kobo)->toBe(2_000_000);
        expect($debit->balance_after)->toBe(3_000_000);
    });

    it('posts a reversal that credits the user back', function () {
        $user = ledgerUser(3_000_000);

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 2_000_000);
            LedgerService::postWithdrawalReversal(
                user:       $user->fresh(),
                amountKobo: 2_000_000,
                reference:  'WDL-001',
                reason:     'Bank rejected transfer',
            );
        });

        $tx = LedgerTransaction::where('reference', 'WDL-001')->where('type', 'withdrawal_reversal')->first();
        expect($tx)->not->toBeNull();

        $lines  = linesFor($tx);
        $credit = $lines->firstWhere('account', "user:{$user->id}:main");
        expect($credit->amount_kobo)->toBe(2_000_000);
        expect($credit->balance_after)->toBe(5_000_000);
    });

    it('posts a completion that moves funds from platform:float to platform:payouts', function () {
        DB::transaction(function () {
            LedgerService::postWithdrawalCompleted(
                amountKobo: 2_000_000,
                reference:  'WDL-001',
            );
        });

        $tx = LedgerTransaction::where('reference', 'WDL-001')->where('type', 'withdrawal_completed')->first();
        expect($tx)->not->toBeNull();

        $lines   = linesFor($tx);
        $debit   = $lines->firstWhere('account', 'platform:float');
        $credit  = $lines->firstWhere('account', 'platform:payouts');

        expect($debit->amount_kobo)->toBe(-2_000_000);
        expect($credit->amount_kobo)->toBe(2_000_000);
    });

    it('withdrawal and reversal idempotency keys are distinct', function () {
        $user = ledgerUser(5_000_000);

        // Both use reference 'WDL-REF-01' but different types → different idempotency keys
        DB::transaction(function () use ($user) {
            $user->decrement('balance_kobo', 1_000_000);
            LedgerService::postWithdrawal(user: $user->fresh(), amountKobo: 1_000_000, reference: 'WDL-REF-01');
        });

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 1_000_000);
            LedgerService::postWithdrawalReversal(user: $user->fresh(), amountKobo: 1_000_000, reference: 'WDL-REF-01');
        });

        expect(LedgerTransaction::where('reference', 'WDL-REF-01')->count())->toBe(2);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Purchases and Sales
// ─────────────────────────────────────────────────────────────────────────────

describe('LedgerService::postPurchase / postSale', function () {

    it('posts a purchase debit from user main to platform:float', function () {
        $user = ledgerUser(10_000_000);

        DB::transaction(function () use ($user) {
            $user->decrement('balance_kobo', 3_000_000);
            LedgerService::postPurchase(
                user:       $user->fresh(),
                amountKobo: 3_000_000,
                reference:  'PUR-001',
                note:       'Plot A, Phase 1',
            );
        });

        $tx    = LedgerTransaction::where('reference', 'PUR-001')->where('type', 'purchase')->first();
        $lines = linesFor($tx);

        expect($lines->firstWhere('account', "user:{$user->id}:main")->amount_kobo)->toBe(-3_000_000);
        expect($lines->firstWhere('account', 'platform:float')->amount_kobo)->toBe(3_000_000);
        expect($lines->sum('amount_kobo'))->toBe(0);
    });

    it('posts a sale credit from platform:float to user main', function () {
        $user = ledgerUser(2_000_000);

        DB::transaction(function () use ($user) {
            $user->increment('balance_kobo', 4_000_000);
            LedgerService::postSale(
                user:       $user->fresh(),
                amountKobo: 4_000_000,
                reference:  'SALE-001',
                note:       'Plot A, Phase 1',
            );
        });

        $tx    = LedgerTransaction::where('reference', 'SALE-001')->where('type', 'sale')->first();
        $lines = linesFor($tx);

        expect($lines->firstWhere('account', 'platform:float')->amount_kobo)->toBe(-4_000_000);
        expect($lines->firstWhere('account', "user:{$user->id}:main")->amount_kobo)->toBe(4_000_000);
        expect($lines->firstWhere('account', "user:{$user->id}:main")->balance_after)->toBe(6_000_000);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Marketplace trades (3-line transactions)
// ─────────────────────────────────────────────────────────────────────────────

describe('LedgerService::postMarketplaceTrade', function () {

    it('posts a 3-line transaction: buyer debit, seller credit, platform fee credit', function () {
        $buyer  = ledgerUser(10_000_000);
        $seller = ledgerUser(0);

        $totalKobo  = 5_000_000;
        $feeKobo    = 250_000;
        $sellerGets = $totalKobo - $feeKobo; // 4_750_000

        DB::transaction(function () use ($buyer, $seller, $totalKobo, $feeKobo, $sellerGets) {
            $buyer->decrement('balance_kobo', $totalKobo);
            $seller->increment('balance_kobo', $sellerGets);

            LedgerService::postMarketplaceTrade(
                buyer:     $buyer->fresh(),
                seller:    $seller->fresh(),
                totalKobo: $totalKobo,
                feeKobo:   $feeKobo,
                reference: 'MKT-001',
            );
        });

        $tx = LedgerTransaction::where('reference', 'MKT-001')->where('type', 'marketplace_trade')->first();
        expect($tx)->not->toBeNull();

        $lines = linesFor($tx);
        expect($lines)->toHaveCount(3);

        expect($lines->firstWhere('account', "user:{$buyer->id}:main")->amount_kobo)->toBe(-5_000_000);
        expect($lines->firstWhere('account', "user:{$seller->id}:main")->amount_kobo)->toBe(4_750_000);
        expect($lines->firstWhere('account', 'platform:fees')->amount_kobo)->toBe(250_000);

        // Core invariant: all 3 lines net to zero
        expect($lines->sum('amount_kobo'))->toBe(0);
    });

    it('records balance_after snapshots correctly for both parties', function () {
        $buyer  = ledgerUser(10_000_000);
        $seller = ledgerUser(1_000_000);

        DB::transaction(function () use ($buyer, $seller) {
            $buyer->decrement('balance_kobo', 2_000_000);
            $seller->increment('balance_kobo', 1_900_000);

            LedgerService::postMarketplaceTrade(
                buyer:     $buyer->fresh(),
                seller:    $seller->fresh(),
                totalKobo: 2_000_000,
                feeKobo:   100_000,
                reference: 'MKT-002',
            );
        });

        $tx    = LedgerTransaction::where('reference', 'MKT-002')->first();
        $lines = linesFor($tx);

        expect($lines->firstWhere('account', "user:{$buyer->id}:main")->balance_after)->toBe(8_000_000);
        expect($lines->firstWhere('account', "user:{$seller->id}:main")->balance_after)->toBe(2_900_000);
        expect($lines->firstWhere('account', 'platform:fees')->balance_after)->toBeNull();
    });

    it('works correctly with zero fee', function () {
        $buyer  = ledgerUser(3_000_000);
        $seller = ledgerUser(0);

        DB::transaction(function () use ($buyer, $seller) {
            $buyer->decrement('balance_kobo', 1_000_000);
            $seller->increment('balance_kobo', 1_000_000);

            LedgerService::postMarketplaceTrade(
                buyer:     $buyer->fresh(),
                seller:    $seller->fresh(),
                totalKobo: 1_000_000,
                feeKobo:   0,
                reference: 'MKT-003',
            );
        });

        $tx    = LedgerTransaction::where('reference', 'MKT-003')->first();
        $lines = linesFor($tx);

        expect($lines->sum('amount_kobo'))->toBe(0);
        expect($lines->firstWhere('account', 'platform:fees')->amount_kobo)->toBe(0);
    });

    it('throws when fee exceeds total', function () {
        $buyer  = ledgerUser(5_000_000);
        $seller = ledgerUser(0);

        expect(fn () => DB::transaction(function () use ($buyer, $seller) {
            LedgerService::postMarketplaceTrade(
                buyer:     $buyer,
                seller:    $seller,
                totalKobo: 1_000_000,
                feeKobo:   2_000_000, // fee > total → sellerGets = -1_000_000 → unbalanced
                reference: 'MKT-BAD',
            );
        }))->toThrow(RuntimeException::class);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Rewards
// ─────────────────────────────────────────────────────────────────────────────

describe('LedgerService rewards methods', function () {

    it('postRewardCredit: credits user:N:rewards, debits platform:rewards_pool', function () {
        $user = ledgerUser(0, 0);

        DB::transaction(function () use ($user) {
            $user->increment('rewards_balance_kobo', 500_000);
            LedgerService::postRewardCredit(
                user:                $user->fresh(),
                amountKobo:          500_000,
                reference:           'REF-REWARD-1',
                note:                'Referral cashback',
                rewardsBalanceAfter: 500_000,
            );
        });

        $tx    = LedgerTransaction::where('reference', 'REF-REWARD-1')->where('type', 'reward_credit')->first();
        $lines = linesFor($tx);

        expect($lines->firstWhere('account', 'platform:rewards_pool')->amount_kobo)->toBe(-500_000);
        expect($lines->firstWhere('account', "user:{$user->id}:rewards")->amount_kobo)->toBe(500_000);
        expect($lines->firstWhere('account', "user:{$user->id}:rewards")->balance_after)->toBe(500_000);
        expect($lines->sum('amount_kobo'))->toBe(0);
    });

    it('postRewardSpend: debits user:N:rewards, credits platform:float', function () {
        $user = ledgerUser(0, 1_000_000);

        DB::transaction(function () use ($user) {
            $user->decrement('rewards_balance_kobo', 300_000);
            LedgerService::postRewardSpend(
                user:                $user->fresh(),
                amountKobo:          300_000,
                reference:           'PUR-REWARD-001',
                note:                'Applied to purchase',
                rewardsBalanceAfter: 700_000,
            );
        });

        $tx    = LedgerTransaction::where('reference', 'PUR-REWARD-001')->where('type', 'reward_spend')->first();
        $lines = linesFor($tx);

        expect($lines->firstWhere('account', "user:{$user->id}:rewards")->amount_kobo)->toBe(-300_000);
        expect($lines->firstWhere('account', 'platform:float')->amount_kobo)->toBe(300_000);
        expect($lines->firstWhere('account', "user:{$user->id}:rewards")->balance_after)->toBe(700_000);
        expect($lines->sum('amount_kobo'))->toBe(0);
    });

    it('postRewardReversal: debits user:N:rewards, credits platform:rewards_pool', function () {
        $user = ledgerUser(0, 500_000);

        DB::transaction(function () use ($user) {
            $user->decrement('rewards_balance_kobo', 500_000);
            LedgerService::postRewardReversal(
                user:                $user->fresh(),
                amountKobo:          500_000,
                reference:           'REF-REWARD-1',
                note:                'Fraud detected',
                rewardsBalanceAfter: 0,
            );
        });

        $tx    = LedgerTransaction::where('reference', 'REF-REWARD-1')->where('type', 'reward_reversal')->first();
        $lines = linesFor($tx);

        expect($lines->firstWhere('account', "user:{$user->id}:rewards")->amount_kobo)->toBe(-500_000);
        expect($lines->firstWhere('account', 'platform:rewards_pool')->amount_kobo)->toBe(500_000);
        expect($lines->sum('amount_kobo'))->toBe(0);
    });

    it('reward credit and reversal use distinct idempotency keys for the same reference', function () {
        $user = ledgerUser(0, 500_000);

        DB::transaction(function () use ($user) {
            $user->increment('rewards_balance_kobo', 500_000);
            LedgerService::postRewardCredit(
                user: $user->fresh(), amountKobo: 500_000,
                reference: 'REF-10', rewardsBalanceAfter: 500_000,
            );
        });

        DB::transaction(function () use ($user) {
            $user->decrement('rewards_balance_kobo', 500_000);
            LedgerService::postRewardReversal(
                user: $user->fresh(), amountKobo: 500_000,
                reference: 'REF-10', rewardsBalanceAfter: 0,
            );
        });

        expect(LedgerTransaction::where('reference', 'REF-10')->count())->toBe(2);
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Full purchase flow: main + rewards combined
// ─────────────────────────────────────────────────────────────────────────────

describe('Combined purchase flow (main + rewards)', function () {

    it('posts separate balanced transactions for the main and rewards portions', function () {
        $user = ledgerUser(8_000_000, 2_000_000);

        DB::transaction(function () use ($user) {
            // ₦30k from main, ₦10k from rewards
            $user->decrement('balance_kobo', 3_000_000);
            LedgerService::postPurchase(
                user:       $user->fresh(),
                amountKobo: 3_000_000,
                reference:  'PUR-COMBO-01',
                note:       'Plot B',
            );

            $user->decrement('rewards_balance_kobo', 1_000_000);
            LedgerService::postRewardSpend(
                user:                $user->fresh(),
                amountKobo:          1_000_000,
                reference:           'PUR-COMBO-01',
                rewardsBalanceAfter: 1_000_000,
            );
        });

        // Two separate transactions, each balanced
        $txs = LedgerTransaction::where('reference', 'PUR-COMBO-01')->get();
        expect($txs)->toHaveCount(2);

        foreach ($txs as $tx) {
            $net = LedgerLine::where('ledger_transaction_id', $tx->id)->sum('amount_kobo');
            expect($net)->toBe(0);
        }

        // Total debited from user = 4_000_000 across both wallets
        $mainDebit    = LedgerLine::where('account', "user:{$user->id}:main")->sum('amount_kobo');
        $rewardsDebit = LedgerLine::where('account', "user:{$user->id}:rewards")->sum('amount_kobo');

        expect($mainDebit)->toBe(-3_000_000);
        expect($rewardsDebit)->toBe(-1_000_000);
    });

});
