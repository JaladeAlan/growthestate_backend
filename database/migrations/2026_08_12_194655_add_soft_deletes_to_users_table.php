<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DELETE /admin/users/{user} previously ran a hard delete, which
     * destroys the audit trail for a financial system (the user row
     * backs ledger entries, deposits, withdrawals, KYC records, etc.
     * via user_id). Soft-deleting preserves that history while still
     * removing the account from normal listings/auth.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
