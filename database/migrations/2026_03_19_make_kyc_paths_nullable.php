<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->string('id_front_path')->nullable()->change();
            $table->string('id_back_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->string('id_front_path')->nullable(false)->change();
            $table->string('id_back_path')->nullable(false)->change();
        });
    }
};
