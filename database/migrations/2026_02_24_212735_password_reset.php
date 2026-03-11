<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_reset_code')->nullable()->change();

            $table->boolean('password_reset_verified')->default(false)->after('password_reset_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('password_reset_code')->nullable()->change();
            $table->dropColumn('password_reset_verified');
        });
    }
};