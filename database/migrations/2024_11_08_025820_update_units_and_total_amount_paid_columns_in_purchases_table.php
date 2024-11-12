<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateUnitsAndTotalAmountPaidColumnsInPurchasesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('purchases', function (Blueprint $table) {
            // Modify the units column to allow negative values (signed integer)
            $table->integer('units')->signed()->change();
            
            // Modify the total_amount_paid column to allow negative values (signed decimal)
            $table->decimal('total_amount_paid', 10, 2)->signed()->change();
            $table->renameColumn('withdrawal_date', 'sell_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            // Revert changes (if necessary, adjust the column back to unsigned)
            $table->integer('units')->unsigned()->change();
            $table->decimal('total_amount_paid', 10, 2)->unsigned()->change();
            $table->renameColumn('sell_date', 'withdrawal_date');
        });
    }
}
