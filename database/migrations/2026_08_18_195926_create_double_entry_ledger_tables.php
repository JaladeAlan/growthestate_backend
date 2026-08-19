<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3: double-entry ledger tables.
     *
     * ledger_transactions — one row per financial event. Holds the
     *   idempotency key so duplicate events (webhook retries, race
     *   conditions) are rejected at the DB level.
     *
     * ledger_lines — exactly two rows per transaction (debit + credit).
     *   Both rows must be inserted in the same DB transaction; if the
     *   pair doesn't net to zero the application-level posting helper
     *   (LedgerService) throws before committing.
     *
     * Accounts are virtual string keys for now — no separate accounts
     * table keeps the migration minimal while the system transitions.
     * Named format: "user:{id}:main", "user:{id}:rewards",
     * "platform:fees", "platform:float".
     *
     * ledger_entries (the old single-entry table) is left untouched;
     * existing code paths still write to it. New deposit paths write
     * here instead. Other flows migrate in subsequent phases.
     */
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();

            // Human-readable type (deposit, transaction_fee, withdrawal, etc.)
            $table->string('type', 64);

            // Unique idempotency key — same reference + type = same event.
            // Format convention: "{type}:{reference}", e.g. "deposit:PST-ABC123"
            $table->string('idempotency_key', 191)->unique();

            // The gateway/domain reference (e.g. Paystack reference, withdrawal id)
            $table->string('reference', 191)->index();

            // Optional human-readable note for audit/admin display
            $table->string('note')->nullable();

            $table->timestamps();

            $table->index('type');
            $table->index('created_at');
        });

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ledger_transaction_id')
                ->constrained('ledger_transactions')
                ->restrictOnDelete();

            // Virtual account key — "user:42:main", "platform:fees", etc.
            $table->string('account', 191)->index();

            // Signed integer in kobo. Positive = credit to this account.
            // Negative = debit from this account. The pair in a transaction
            // must sum to zero: credit_line->amount + debit_line->amount = 0.
            $table->bigInteger('amount_kobo');

            // Snapshot of the account's balance immediately after this line
            // was posted. Null for platform accounts (no cached balance).
            $table->bigInteger('balance_after')->nullable();

            $table->timestamps();

            $table->index(['account', 'created_at']);
            $table->index('ledger_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('ledger_transactions');
    }
};
