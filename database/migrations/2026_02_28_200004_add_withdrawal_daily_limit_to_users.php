<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('withdrawal_daily_total_kobo')->default(0)->after('rewards_balance_kobo');
            $table->date('withdrawal_day')->nullable()->after('withdrawal_daily_total_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_daily_total_kobo', 'withdrawal_day']);
        });
    }
};
