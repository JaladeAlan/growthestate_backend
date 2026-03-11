<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('transaction_pin')->nullable()->after('password');
            $table->string('pin_reset_code')->nullable();
            $table->timestamp('pin_reset_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('transaction_pin');
            $table->dropColumn('pin_reset_code');
            $table->dropColumn('pin_reset_expires_at');
        });
    }
};
