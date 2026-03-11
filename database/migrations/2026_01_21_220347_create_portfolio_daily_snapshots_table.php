<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portfolio_daily_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('snapshot_date');

            $table->integer('total_units');
            $table->bigInteger('total_invested_kobo');
            $table->bigInteger('total_portfolio_value_kobo');

            $table->bigInteger('profit_loss_kobo');
            $table->decimal('profit_loss_percent', 8, 4);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'snapshot_date'], 'portfolio_snapshot_unique');

            // Chart-critical indexes
            $table->index(['user_id', 'snapshot_date'], 'idx_portfolio_snapshots_user_date');
            $table->index('snapshot_date', 'idx_portfolio_snapshots_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_daily_snapshots');
    }
};
