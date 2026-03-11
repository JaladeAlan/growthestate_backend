<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Rename column if it exists
        if (Schema::hasColumn('withdrawals', 'amount')) {
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->renameColumn('amount', 'amount_kobo');
            });
        }

        // Step 2: Change type to integer if column exists
        if (Schema::hasColumn('withdrawals', 'amount_kobo')) {
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->integer('amount_kobo')->change();
            });
        }
    }

    public function down(): void
    {
        // Step 1: Revert type if column exists
        if (Schema::hasColumn('withdrawals', 'amount_kobo')) {
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->decimal('amount_kobo', 15, 2)->change();
            });
        }

        // Step 2: Rename back to original
        if (Schema::hasColumn('withdrawals', 'amount_kobo')) {
            Schema::table('withdrawals', function (Blueprint $table) {
                $table->renameColumn('amount_kobo', 'amount');
            });
        }
    }
};
