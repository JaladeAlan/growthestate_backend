<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Phase 1 (idempotency): every real money-moving action already has a
     * unique reference at its source (deposits.reference, withdrawals.reference,
     * marketplace_transactions.reference, referral_rewards.id via
     * 'REF-REWARD-{id}'). Those source-table constraints are the primary
     * guard, checked under a row lock before a ledger entry is ever written.
     *
     * This adds a second, independent guard directly on ledger_entries: even
     * if a future code path forgets to lock/check upstream, the database
     * itself refuses to record the same (reference, type) combination twice.
     * A unique violation here should never happen in normal operation — if
     * it fires, it means a real double-write was just prevented and is worth
     * investigating, not silently retried.
     */
    public function up(): void
    {
        // Guard against pre-existing duplicates blocking the migration.
        // If any exist, they indicate a real historical double-credit/debit
        // bug and should be investigated manually rather than papered over.
        $duplicates = DB::table('ledger_entries')
            ->select('reference', 'type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('reference', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            Log::warning('ledger_entries has pre-existing (reference, type) duplicates — unique index NOT applied', [
                'duplicate_count' => $duplicates->count(),
                'examples'        => $duplicates->take(10)->toArray(),
            ]);

            // Do not fail the deploy — surface it and skip the index so the
            // app keeps running. Re-run this migration (or add the index
            // manually) once the duplicates are reconciled.
            return;
        }

        DB::statement('
            CREATE UNIQUE INDEX ledger_entries_reference_type_unique
            ON ledger_entries (reference, type)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ledger_entries_reference_type_unique');
    }
};
