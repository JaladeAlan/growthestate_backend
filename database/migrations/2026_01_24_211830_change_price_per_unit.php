<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lands', function (Blueprint $table) {
            $table->bigInteger('price_per_unit_kobo')->nullable()->after('price_per_unit');
        });

        DB::table('lands')->update([
            'price_per_unit_kobo' => DB::raw('price_per_unit * 100')
        ]);

        Schema::table('lands', function (Blueprint $table) {
            $table->bigInteger('price_per_unit_kobo')->nullable(false)->change();
        });

        Schema::table('lands', function (Blueprint $table) {
            $table->dropColumn('price_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('lands', function (Blueprint $table) {
            $table->decimal('price_per_unit', 15, 2)->nullable()->after('price_per_unit_kobo');
        });

        DB::table('lands')->update([
            'price_per_unit' => DB::raw('price_per_unit_kobo / 100.0')
        ]);

        Schema::table('lands', function (Blueprint $table) {
            $table->dropColumn('price_per_unit_kobo');
        });
    }
};
