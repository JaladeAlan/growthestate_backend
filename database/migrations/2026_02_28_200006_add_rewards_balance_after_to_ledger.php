<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('ledger_entries', 'rewards_balance_after')) {
                $table->bigInteger('rewards_balance_after')->nullable()->after('balance_after');
            }

            if (! Schema::hasColumn('ledger_entries', 'note')) {
                $table->string('note')->nullable()->after('rewards_balance_after');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            if (Schema::hasColumn('ledger_entries', 'rewards_balance_after')) {
                $table->dropColumn('rewards_balance_after');
            }

            if (Schema::hasColumn('ledger_entries', 'note')) {
                $table->dropColumn('note');
            }
        });
    }
};