<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('reference')->unique();

            // All monetary values stored in kobo (integers — no floating point)
            $table->bigInteger('amount_kobo');
            $table->bigInteger('transaction_fee')->default(0);
            $table->bigInteger('total_kobo');

            $table->string('gateway'); // 'paystack' | 'monnify'

            $table->string('status')->default('pending');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE deposits
                ADD CONSTRAINT deposits_status_check
                CHECK (status IN ('pending', 'completed', 'failed'))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};