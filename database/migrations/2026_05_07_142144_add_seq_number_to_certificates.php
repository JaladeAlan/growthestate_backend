<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->unsignedInteger('sequence_number')->default(0)->after('purchase_id');
            $table->timestamp('last_updated_at')->nullable()->after('issued_at');

            $table->index(['land_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex(['land_id', 'sequence_number']);
            $table->dropColumn(['sequence_number', 'last_updated_at']);
        });
    }
};