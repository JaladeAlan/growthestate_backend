<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'amount_kobo')) {
                $table->unsignedBigInteger('amount_kobo')->default(0);
            }
        });

        if (Schema::hasColumn('transactions', 'amount')) {
            DB::table('transactions')->update([
                'amount_kobo' => DB::raw('amount * 100')
            ]);
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'amount')) {
                $table->dropColumn('amount');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'amount')) {
                $table->decimal('amount', 15, 2)->default(0);
            }
        });

        if (Schema::hasColumn('transactions', 'amount_kobo')) {
            DB::table('transactions')->update([
                'amount' => DB::raw('amount_kobo / 100')
            ]);
        }

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'amount_kobo')) {
                $table->dropColumn('amount_kobo');
            }
        });
    }
};
