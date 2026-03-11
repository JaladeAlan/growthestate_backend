<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Check if constraint doesn't already exist before adding
        if (!$this->constraintExists('portfolio_asset_snapshots', 'unique_user_asset_snapshot')) {
            Schema::table('portfolio_asset_snapshots', function (Blueprint $table) {
                $table->unique(['user_id', 'land_id', 'snapshot_date'], 'unique_user_asset_snapshot');
            });
        }

        if (!$this->constraintExists('portfolio_daily_snapshots', 'unique_user_daily_snapshot')) {
            Schema::table('portfolio_daily_snapshots', function (Blueprint $table) {
                $table->unique(['user_id', 'snapshot_date'], 'unique_user_daily_snapshot');
            });
        }
    }

    public function down(): void
    {
        Schema::table('portfolio_daily_snapshots', function (Blueprint $table) {
            $table->dropUnique('unique_user_daily_snapshot');
        });

        Schema::table('portfolio_asset_snapshots', function (Blueprint $table) {
            $table->dropUnique('unique_user_asset_snapshot');
        });
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        // For PostgreSQL
        $result = DB::select("
            SELECT 1 
            FROM pg_constraint 
            WHERE conname = ?
        ", [$constraint]);

        return !empty($result);
    }
};