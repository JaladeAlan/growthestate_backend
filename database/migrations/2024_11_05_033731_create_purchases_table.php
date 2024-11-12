<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePurchasesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Foreign key for users
            $table->foreignId('land_id')->constrained()->onDelete('cascade'); // Foreign key for lands
            $table->integer('units')->default(0); // Number of units purchased
            $table->decimal('total_amount_paid', 10, 2); // Total amount paid for the purchase
            $table->timestamp('purchase_date')->nullable(); // Date of purchase
            $table->integer('units_sold')->default(0); // Units sold (if applicable)
            $table->decimal('total_amount_received', 10, 2)->default(0); // Total amount received from sales
            $table->timestamp('withdrawal_date')->nullable(); // Date of withdrawal
            $table->string('reference')->unique()->nullable(); // Unique reference for the purchase
            $table->string('status')->default('completed'); // Status of the purchase, default to completed
            $table->timestamps(); // Created at and updated at timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases'); // Drop the purchases table
    }
}
