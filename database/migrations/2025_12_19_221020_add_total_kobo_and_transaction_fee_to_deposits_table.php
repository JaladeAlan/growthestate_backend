<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('deposits')) {
            return;
        }

        Schema::table('deposits', function (Blueprint $table) {

            if (!Schema::hasColumn('deposits', 'transaction_fee')) {
                $table->unsignedBigInteger('transaction_fee')
                    ->default(0);
            }

            if (!Schema::hasColumn('deposits', 'total_kobo')) {
                $table->unsignedBigInteger('total_kobo')
                    ->default(0);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('deposits')) {
            return;
        }

        Schema::table('deposits', function (Blueprint $table) {

            if (Schema::hasColumn('deposits', 'transaction_fee')) {
                $table->dropColumn('transaction_fee');
            }

            if (Schema::hasColumn('deposits', 'total_kobo')) {
                $table->dropColumn('total_kobo');
            }
        });
    }
};
