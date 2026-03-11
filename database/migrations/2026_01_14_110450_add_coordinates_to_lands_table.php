<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {

    public function up(): void
    {
        DB::statement("
            ALTER TABLE lands
            ADD COLUMN coordinates POINT
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE lands
            DROP COLUMN IF EXISTS coordinates
        ");
    }
};
