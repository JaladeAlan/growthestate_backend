<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS land_price_unique_per_day
            ON land_price_history (land_id, price_date)
        ");
    }

    public function down(): void
    {
        DB::statement("
            DROP INDEX IF EXISTS land_price_unique_per_day
        ");
    }
};
