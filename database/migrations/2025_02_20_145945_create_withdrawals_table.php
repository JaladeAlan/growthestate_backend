<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('withdrawals')) {
            Schema::create('withdrawals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('reference')->unique();
                $table->bigInteger('amount_kobo');
                $table->string('gateway')->nullable();
                $table->string('status')->default('pending');
                $table->timestamps();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE withdrawals
                DROP CONSTRAINT IF EXISTS withdrawals_status_check
            ");
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