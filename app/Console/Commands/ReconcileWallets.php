<?php

namespace App\Console\Commands;

use App\Models\LedgerLine;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4(b): hourly reconciliation comparing cached wallet balances against
 * the double-entry ledger (ledger_lines), which is now the source of truth
 * for all five money-moving flows (deposits, withdrawals, purchases/sales,
 * marketplace trades, rewards).
 *
 * Strategy: every ledger_line for a user account carries balance_after —
 * the balance snapshot at the moment that line was posted. The latest
 * balance_after for "user:{id}:main" is what balance_kobo *should* be.
 * Same for "user:{id}:rewards" vs rewards_balance_kobo.
 * Comparing snapshots avoids re-deriving per-type sign logic here.
 */
class ReconcileWallets extends Command
{
    protected $signature   = 'wallets:reconcile';
    protected $description = 'Compare cached user wallet balances against the double-entry ledger';

    public function handle(): int
    {
        $startedAt    = now();
        $mismatches   = [];
        $usersChecked = 0;

        try {
            User::query()
                ->select('id', 'balance_kobo', 'rewards_balance_kobo')
                ->orderBy('id')
                ->chunkById(500, function ($users) use (&$mismatches, &$usersChecked) {
                    $userIds = $users->pluck('id');

                    // Latest balance_after for each user's main account.
                    $latestMain = LedgerLine::whereIn('account', $userIds->map(fn ($id) => "user:{$id}:main"))
                        ->whereNotNull('balance_after')
                        ->select('account', 'balance_after')
                        ->whereIn('id', function ($q) use ($userIds) {
                            $q->selectRaw('MAX(id)')
                                ->from('ledger_lines')
                                ->whereIn('account', $userIds->map(fn ($id) => "user:{$id}:main"))
                                ->whereNotNull('balance_after')
                                ->groupBy('account');
                        })
                        ->get()
                        ->keyBy(fn ($row) => (int) str($row->account)->after('user:')->before(':main'));

                    // Latest balance_after for each user's rewards account.
                    $latestRewards = LedgerLine::whereIn('account', $userIds->map(fn ($id) => "user:{$id}:rewards"))
                        ->whereNotNull('balance_after')
                        ->select('account', 'balance_after')
                        ->whereIn('id', function ($q) use ($userIds) {
                            $q->selectRaw('MAX(id)')
                                ->from('ledger_lines')
                                ->whereIn('account', $userIds->map(fn ($id) => "user:{$id}:rewards"))
                                ->whereNotNull('balance_after')
                                ->groupBy('account');
                        })
                        ->get()
                        ->keyBy(fn ($row) => (int) str($row->account)->after('user:')->before(':rewards'));

                    foreach ($users as $user) {
                        $usersChecked++;

                        $expectedMain    = $latestMain->has($user->id)
                            ? (int) $latestMain[$user->id]->balance_after
                            : 0;

                        $expectedRewards = $latestRewards->has($user->id)
                            ? (int) $latestRewards[$user->id]->balance_after
                            : 0;

                        $mainDrift    = $expectedMain    !== (int) $user->balance_kobo;
                        $rewardsDrift = $expectedRewards !== (int) $user->rewards_balance_kobo;

                        if ($mainDrift || $rewardsDrift) {
                            $mismatches[] = [
                                'user_id'             => $user->id,
                                'cached_balance_kobo'  => (int) $user->balance_kobo,
                                'ledger_balance_kobo'  => $expectedMain,
                                'cached_rewards_kobo'  => (int) $user->rewards_balance_kobo,
                                'ledger_rewards_kobo'  => $expectedRewards,
                            ];
                        }
                    }
                });

            $status = empty($mismatches) ? 'ok' : 'drift_detected';

            DB::table('wallet_reconciliation_runs')->insert([
                'started_at'       => $startedAt,
                'finished_at'      => now(),
                'users_checked'    => $usersChecked,
                'mismatches_found' => count($mismatches),
                'mismatches'       => empty($mismatches) ? null : json_encode($mismatches),
                'status'           => $status,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            if (empty($mismatches)) {
                $this->info("Reconciliation OK — {$usersChecked} users checked, no drift found.");
                return self::SUCCESS;
            }

            $this->error(count($mismatches) . " user(s) with wallet drift out of {$usersChecked} checked:");
            foreach ($mismatches as $m) {
                $this->line(json_encode($m));
            }

            Log::critical('Wallet reconciliation found drift', [
                'users_checked'    => $usersChecked,
                'mismatches_found' => count($mismatches),
                'mismatches'       => $mismatches,
            ]);

            return self::FAILURE;

        } catch (\Throwable $e) {
            DB::table('wallet_reconciliation_runs')->insert([
                'started_at'    => $startedAt,
                'finished_at'   => now(),
                'users_checked' => $usersChecked,
                'status'        => 'failed',
                'error'         => $e->getMessage(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            Log::error('Wallet reconciliation run failed', ['error' => $e->getMessage()]);
            $this->error('Reconciliation run failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
