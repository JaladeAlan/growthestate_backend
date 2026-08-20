<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Mirrors 2026_08_18_144500_add_append_only_trigger_to_ledger_entries.php,
     * but for the double-entry tables (ledger_transactions, ledger_lines) that
     * LedgerService actually writes to. LedgerTransaction::booted() and
     * LedgerLine::booted() already block update/delete at the Eloquent layer,
     * but — same reasoning as the legacy trigger — that doesn't hold for raw
     * SQL, migrations, tinker, or any future code path that bypasses the model.
     *
     * No-op on non-Postgres connections (e.g. sqlite in tests) since
     * BEFORE UPDATE/DELETE triggers with RAISE EXCEPTION are Postgres
     * syntax; the model guards still apply there regardless.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE OR REPLACE FUNCTION prevent_ledger_double_entry_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    \'% is append-only: % is not permitted (id=%)\',
                    TG_TABLE_NAME, TG_OP, OLD.id;
            END;
            $$ LANGUAGE plpgsql;
        ');

        foreach (['ledger_transactions', 'ledger_lines'] as $table) {
            DB::statement("
                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION prevent_ledger_double_entry_mutation();
            ");

            DB::statement("
                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION prevent_ledger_double_entry_mutation();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['ledger_transactions', 'ledger_lines'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_no_update ON {$table}");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_no_delete ON {$table}");
        }

        DB::statement('DROP FUNCTION IF EXISTS prevent_ledger_double_entry_mutation()');
    }
};
