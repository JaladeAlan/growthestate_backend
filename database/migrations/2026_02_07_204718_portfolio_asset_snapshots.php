<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('portfolio_asset_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('land_id');
            $table->date('snapshot_date');
            $table->decimal('units', 20, 8); // Supports fractional units
            $table->unsignedBigInteger('value_kobo'); // Value in kobo (smallest currency unit)
            $table->timestamp('created_at')->nullable();

            // Indexes
            $table->index('user_id');
            $table->index('land_id');
            $table->index('snapshot_date');
            $table->index(['user_id', 'snapshot_date']); // For querying user's portfolio on a date
            
            // Unique constraint to prevent duplicate snapshots
            $table->unique(['user_id', 'land_id', 'snapshot_date'], 'unique_user_asset_snapshot');

            // Foreign keys (optional - add if you want referential integrity)
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
            
            $table->foreign('land_id')
                ->references('id')
                ->on('lands') // or whatever your land table is called
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_asset_snapshots');
    }
};