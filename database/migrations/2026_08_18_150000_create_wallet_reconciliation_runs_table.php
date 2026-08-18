<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4(b): balance_kobo/rewards_balance_kobo stay as the live,
     * frequently-read cache — this table is the reconciliation history
     * comparing that cache against the ledger's computed sum, so drift
     * is visible and queryable rather than only ever appearing in logs.
     */
    public function up(): void
    {
        Schema::create('wallet_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('users_checked')->default(0);
            $table->unsignedInteger('mismatches_found')->default(0);

            // Full list of mismatched users for this run, each with
            // ledger-computed vs cached balances for both wallets.
            $table->json('mismatches')->nullable();

            $table->enum('status', ['ok', 'drift_detected', 'failed'])->default('ok');
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_reconciliation_runs');
    }
};
