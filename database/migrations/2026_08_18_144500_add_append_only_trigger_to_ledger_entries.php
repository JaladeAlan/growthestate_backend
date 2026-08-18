<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Phase 2 (append-only log). LedgerEntry::booted() already blocks
     * update/delete at the Eloquent layer. This adds the same rule as a
     * Postgres trigger so it also holds for raw SQL, migrations, tinker,
     * or any future code path that bypasses the model.
     *
     * No-op on non-Postgres connections (e.g. sqlite in tests) since
     * BEFORE UPDATE/DELETE triggers with RAISE EXCEPTION are Postgres
     * syntax; the model guard still applies there regardless.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE OR REPLACE FUNCTION prevent_ledger_entries_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    \'ledger_entries is append-only: % is not permitted (id=%)\',
                    TG_OP, OLD.id;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::statement('
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_entries_mutation();
        ');

        DB::statement('
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_entries_mutation();
        ');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries');
        DB::statement('DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries');
        DB::statement('DROP FUNCTION IF EXISTS prevent_ledger_entries_mutation()');
    }
};
