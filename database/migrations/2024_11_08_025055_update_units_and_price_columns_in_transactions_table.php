<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateUnitsAndPriceColumnsInTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Modify the units column to allow negative values (signed integer)
            $table->integer('units')->signed()->change();
            
            // Modify the price column to allow negative values (signed decimal)
            $table->decimal('price', 10, 2)->signed()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Revert changes (if necessary, adjust the column back to unsigned)
            $table->integer('units')->unsigned()->change();
            $table->decimal('price', 10, 2)->unsigned()->change();
        });
    }
}
