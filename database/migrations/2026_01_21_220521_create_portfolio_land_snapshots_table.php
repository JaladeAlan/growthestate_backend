<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portfolio_land_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('land_id')
                ->constrained('lands')
                ->cascadeOnDelete();

            $table->date('snapshot_date');

            $table->integer('units_owned');
            $table->bigInteger('invested_kobo');
            $table->bigInteger('land_value_kobo');
            $table->bigInteger('profit_loss_kobo');

            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['user_id', 'land_id', 'snapshot_date'],
                'portfolio_land_snapshot_unique'
            );

            $table->index(
                ['user_id', 'snapshot_date'],
                'idx_portfolio_land_snapshots_user_date'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_land_snapshots');
    }
};
