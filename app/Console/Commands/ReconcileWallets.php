<?php

namespace App\Console\Commands;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4(b) of wallet hardening: balance_kobo / rewards_balance_kobo on
 * `users` remain the live, frequently-read cache. This command is the
 * reconciliation check that catches drift between that cache and the
 * ledger, which is the actual source of truth for "what happened."
 *
 * Reconciliation strategy: every wallet-mutating code path writes
 * balance_after (and, for reward entries, rewards_balance_after) as the
 * resulting balance at the time of that entry — not just a signed delta.
 * So the latest ledger entry for a user already states what the cache
 * *should* currently read. Comparing against that avoids re-deriving
 * sign-per-type logic here (deposit=+, withdrawal=-, etc.) that could
 * itself drift out of sync with the application code and mask real bugs.
 */
class ReconcileWallets extends Command
{
    protected $signature   = 'wallets:reconcile {--fix : Log mismatches only, never writes to users table}';
    protected $description = 'Compare cached user wallet balances against the ledger and report any drift';

    public function handle(): int
    {
        $startedAt = now();
        $mismatches = [];
        $usersChecked = 0;

        try {
            User::query()
                ->select('id', 'balance_kobo', 'rewards_balance_kobo')
                ->orderBy('id')
                ->chunkById(500, function ($users) use (&$mismatches, &$usersChecked) {
                    $userIds = $users->pluck('id');

                    // Latest main-balance ledger entry per user (balance_after
                    // is populated on every ledger row regardless of type).
                    $latestMain = LedgerEntry::whereIn('uid', $userIds)
                        ->select('uid', 'balance_after', 'id')
                        ->whereIn('id', function ($q) use ($userIds) {
                            $q->selectRaw('MAX(id)')
                                ->from('ledger_entries')
                                ->whereIn('uid', $userIds)
                                ->groupBy('uid');
                        })
                        ->get()
                        ->keyBy('uid');

                    // Latest rewards-balance ledger entry per user — only
                    // reward_credit / reward_spend rows populate this column.
                    $latestRewards = LedgerEntry::whereIn('uid', $userIds)
                        ->whereNotNull('rewards_balance_after')
                        ->select('uid', 'rewards_balance_after', 'id')
                        ->whereIn('id', function ($q) use ($userIds) {
                            $q->selectRaw('MAX(id)')
                                ->from('ledger_entries')
                                ->whereIn('uid', $userIds)
                                ->whereNotNull('rewards_balance_after')
                                ->groupBy('uid');
                        })
                        ->get()
                        ->keyBy('uid');

                    foreach ($users as $user) {
                        $usersChecked++;

                        $mainEntry    = $latestMain->get($user->id);
                        $rewardsEntry = $latestRewards->get($user->id);

                        // No ledger history at all for a user with a nonzero
                        // cached balance is itself a finding — every real
                        // credit/debit path writes a ledger row.
                        $expectedMain    = $mainEntry ? (int) $mainEntry->balance_after : 0;
                        $expectedRewards = $rewardsEntry ? (int) $rewardsEntry->rewards_balance_after : 0;

                        $mainDrift    = $expectedMain !== (int) $user->balance_kobo;
                        $rewardsDrift = $expectedRewards !== (int) $user->rewards_balance_kobo;

                        if ($mainDrift || $rewardsDrift) {
                            $mismatches[] = [
                                'user_id'                => $user->id,
                                'cached_balance_kobo'     => (int) $user->balance_kobo,
                                'ledger_balance_kobo'     => $expectedMain,
                                'cached_rewards_kobo'     => (int) $user->rewards_balance_kobo,
                                'ledger_rewards_kobo'     => $expectedRewards,
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

            // critical, not just error: unexplained wallet drift is a
            // financial-integrity incident, not routine noise.
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
