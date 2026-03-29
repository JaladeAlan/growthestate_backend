<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('land_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1–12
            $table->decimal('value', 15, 2);
            $table->timestamps();

            $table->unique(['land_id', 'year', 'month']); // one entry per land per year+month
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('land_valuations');
    }
};