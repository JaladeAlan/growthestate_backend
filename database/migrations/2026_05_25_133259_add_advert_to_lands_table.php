<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lands', function (Blueprint $table) {
            $table->unsignedBigInteger('pre_launch_price_kobo')->nullable()->after('rental_pa');
            $table->unsignedBigInteger('launch_price_kobo')->nullable()->after('pre_launch_price_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('lands', function (Blueprint $table) {
            $table->dropColumn(['pre_launch_price_kobo', 'launch_price_kobo']);
        });
    }
};