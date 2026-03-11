<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('reference')->unique();

            // Stored in kobo — consistent with the rest of the codebase
            $table->bigInteger('amount_kobo');

            $table->string('gateway')->nullable(); // 'paystack' etc.

            $table->string('status')->default('pending');

            $table->timestamps();
        });

        // Enforce valid statuses via CHECK (works on Postgres, MySQL, SQLite 3.25+)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE withdrawals
                ADD CONSTRAINT withdrawals_status_check
                CHECK (status IN ('pending', 'processing', 'completed', 'failed'))
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};