<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {

            if (!Schema::hasColumn('purchases', 'total_amount_paid_kobo')) {
                $table->unsignedBigInteger('total_amount_paid_kobo')->default(0);
            }

            if (!Schema::hasColumn('purchases', 'total_amount_received_kobo')) {
                $table->unsignedBigInteger('total_amount_received_kobo')->default(0);
            }
        });

        if (
            Schema::hasColumn('purchases', 'total_amount_paid') &&
            Schema::hasColumn('purchases', 'total_amount_received')
        ) {
            DB::table('purchases')->update([
                'total_amount_paid_kobo'     => DB::raw('total_amount_paid * 100'),
                'total_amount_received_kobo' => DB::raw('total_amount_received * 100'),
            ]);
        }

        Schema::table('purchases', function (Blueprint $table) {

            if (Schema::hasColumn('purchases', 'total_amount_paid')) {
                $table->dropColumn('total_amount_paid');
            }

            if (Schema::hasColumn('purchases', 'total_amount_received')) {
                $table->dropColumn('total_amount_received');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {

            if (!Schema::hasColumn('purchases', 'total_amount_paid')) {
                $table->decimal('total_amount_paid', 12, 2)->default(0);
            }

            if (!Schema::hasColumn('purchases', 'total_amount_received')) {
                $table->decimal('total_amount_received', 12, 2)->default(0);
            }
        });

        if (
            Schema::hasColumn('purchases', 'total_amount_paid_kobo') &&
            Schema::hasColumn('purchases', 'total_amount_received_kobo')
        ) {
            DB::table('purchases')->update([
                'total_amount_paid'     => DB::raw('total_amount_paid_kobo / 100'),
                'total_amount_received' => DB::raw('total_amount_received_kobo / 100'),
            ]);
        }

        Schema::table('purchases', function (Blueprint $table) {

            if (Schema::hasColumn('purchases', 'total_amount_paid_kobo')) {
                $table->dropColumn('total_amount_paid_kobo');
            }

            if (Schema::hasColumn('purchases', 'total_amount_received_kobo')) {
                $table->dropColumn('total_amount_received_kobo');
            }
        });
    }
};
