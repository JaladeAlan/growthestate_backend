<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('ledger_entries', function (Blueprint $table) {
        $table->id();
        $table->foreignId('uid')->constrained('users')->cascadeOnDelete();

        $table->enum('type', [
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
        ]);

        $table->bigInteger('amount_kobo');
        $table->bigInteger('balance_after');

        $table->string('reference')->index();
        $table->timestamps();
    });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
