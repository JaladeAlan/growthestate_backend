<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('lands', function (Blueprint $table) {
        $table->dropColumn('price_per_unit_kobo');
    });
}

public function down()
{
    Schema::table('lands', function (Blueprint $table) {
        $table->integer('price_per_unit_kobo')->notNullable();
    });
}
};
