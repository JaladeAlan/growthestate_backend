<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * withdrawals_status_check never included 'rejected', but
     * WithdrawalController::adminReject() sets exactly that status —
     * every admin rejection of a suspicious withdrawal 500s in production.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE withdrawals DROP CONSTRAINT IF EXISTS withdrawals_status_check');

        DB::statement("
            ALTER TABLE withdrawals
            ADD CONSTRAINT withdrawals_status_check
            CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'rejected'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE withdrawals DROP CONSTRAINT IF EXISTS withdrawals_status_check');

        DB::statement("
            ALTER TABLE withdrawals
            ADD CONSTRAINT withdrawals_status_check
            CHECK (status IN ('pending', 'processing', 'completed', 'failed'))
        ");
    }
};
