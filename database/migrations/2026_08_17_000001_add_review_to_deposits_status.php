<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * deposits_status_check never included 'review', but
     * OpayWebhookController::handle() sets exactly that status when a
     * webhook's verified amount doesn't match the deposit's expected
     * amount — the fraud-flagging path for a mismatched deposit currently
     * throws (both Deposit::STATUS_REVIEW being undefined, fixed
     * separately in the model, and this constraint rejecting the value
     * outright even once the constant exists).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE deposits DROP CONSTRAINT IF EXISTS deposits_status_check');

        DB::statement("
            ALTER TABLE deposits
            ADD CONSTRAINT deposits_status_check
            CHECK (status IN ('pending', 'completed', 'failed', 'review'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deposits DROP CONSTRAINT IF EXISTS deposits_status_check');

        DB::statement("
            ALTER TABLE deposits
            ADD CONSTRAINT deposits_status_check
            CHECK (status IN ('pending', 'completed', 'failed'))
        ");
    }
};
