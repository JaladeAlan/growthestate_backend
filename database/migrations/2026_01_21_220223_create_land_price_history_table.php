<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('land_price_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('land_id')
                ->constrained('lands')
                ->cascadeOnDelete();

            $table->bigInteger('price_per_unit_kobo');
            $table->date('price_date');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['land_id', 'price_date'], 'land_price_unique_per_day');
            $table->index(['land_id', 'price_date'], 'idx_land_price_land_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('land_price_history');
    }
};
