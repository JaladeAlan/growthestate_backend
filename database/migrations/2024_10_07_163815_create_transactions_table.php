<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id(); // Auto-incrementing ID for the transaction
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Foreign key to users table
            $table->foreignId('land_id')->constrained()->onDelete('cascade'); // Foreign key to lands table
            $table->unsignedInteger('units'); // Number of units purchased
            $table->decimal('price', 10, 2); // Total price of the transaction
            $table->enum('status', ['pending', 'completed', 'failed']); // Status of the transaction
            $table->timestamps(); // Created at and updated at timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions'); // Drop the transactions table
    }
}
