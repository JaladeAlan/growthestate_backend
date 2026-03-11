<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {

            // Insert one price history row per land
            DB::statement("
                INSERT INTO land_price_history (
                    land_id,
                    price_per_unit_kobo,
                    price_date,
                    created_at
                )
                SELECT
                    id AS land_id,
                    price_per_unit_kobo,
                    COALESCE(created_at::date, CURRENT_DATE) AS price_date,
                    NOW() AS created_at
                FROM lands
                WHERE price_per_unit_kobo IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM land_price_history lph
                      WHERE lph.land_id = lands.id
                  )
            ");
        });
    }

    public function down(): void
    {
        // Safe rollback: remove ONLY auto-generated initial rows
        DB::statement("
            DELETE FROM land_price_history
            WHERE price_date = CURRENT_DATE
        ");
    }
};
