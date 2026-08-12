<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ledger_entries.type never had marketplace_purchase / marketplace_sale
     * added when the peer-to-peer marketplace feature was built, so every
     * completed trade violates the check constraint and 500s.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_type_check');

        DB::statement("
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN (
                'deposit',
                'withdrawal',
                'reversal',
                'purchase',
                'sale',
                'adjustment',
                'withdrawal_reversal',
                'reward_credit',
                'reward_spend',
                'transaction_fee',
                'marketplace_purchase',
                'marketplace_sale'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT IF EXISTS ledger_entries_type_check');

        DB::statement("
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_type_check
            CHECK (type IN (
                'deposit',
                'withdrawal',
                'reversal',
                'purchase',
                'sale',
                'adjustment',
                'withdrawal_reversal',
                'reward_credit',
                'reward_spend',
                'transaction_fee'
            ))
        ");
    }
};
