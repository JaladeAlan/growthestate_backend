<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Rename `price` to `amount`
            if (Schema::hasColumn('transactions', 'price')) {
                $table->renameColumn('price', 'amount');
            }

            // Add `type` column (e.g. purchase, sale, deposit, withdrawal)
            if (!Schema::hasColumn('transactions', 'type')) {
                $table->string('type')->default('purchase')->after('land_id');
            }

            // Add `transaction_date` for sorting/filtering
            if (!Schema::hasColumn('transactions', 'transaction_date')) {
                $table->timestamp('transaction_date')->nullable()->after('message');
            }
        });

        // Update all existing records to have `type = 'purchase'`
        DB::table('transactions')->update(['type' => 'purchase']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Revert changes safely
            if (Schema::hasColumn('transactions', 'amount')) {
                $table->renameColumn('amount', 'price');
            }

            if (Schema::hasColumn('transactions', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('transactions', 'transaction_date')) {
                $table->dropColumn('transaction_date');
            }
        });
    }
};
