<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Disable transactions for PostgreSQL CONCURRENTLY indexes
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY uq_portfolio_daily_user_date
            ON portfolio_daily_snapshots (user_id, snapshot_date)
        ");

        DB::statement("
            CREATE UNIQUE INDEX CONCURRENTLY uq_portfolio_land_user_land_date
            ON portfolio_land_snapshots (user_id, land_id, snapshot_date)
        ");

        DB::statement("
            CREATE INDEX CONCURRENTLY idx_portfolio_daily_user_date
            ON portfolio_daily_snapshots (user_id, snapshot_date)
        ");

        DB::statement("
            CREATE INDEX CONCURRENTLY idx_portfolio_land_user_land_date
            ON portfolio_land_snapshots (user_id, land_id, snapshot_date)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS uq_portfolio_daily_user_date");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS uq_portfolio_land_user_land_date");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_portfolio_daily_user_date");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS idx_portfolio_land_user_land_date");
    }
};
