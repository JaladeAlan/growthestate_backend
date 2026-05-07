<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the incomplete index from the previous migration
        DB::statement('DROP INDEX IF EXISTS land_price_lookup');

        // Recreate with created_at DESC tiebreaker and INCLUDE for index-only scan
        DB::statement('
            CREATE INDEX land_price_lookup
            ON land_price_history (land_id, price_date DESC, created_at DESC)
            INCLUDE (price_per_unit_kobo)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS land_price_lookup');

        // Restore the previous (incomplete) state so rollback is consistent
        DB::statement('
            CREATE INDEX land_price_lookup
            ON land_price_history (land_id, price_date DESC)
        ');
    }
};