<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE lands ALTER COLUMN coordinates TYPE geometry(Geometry, 4326) USING coordinates::geometry');
        
        // Recreate spatial index if it exists
        DB::statement('DROP INDEX IF EXISTS lands_coordinates_idx');
        DB::statement('CREATE INDEX lands_coordinates_idx ON lands USING GIST (coordinates)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE lands ALTER COLUMN coordinates TYPE geometry(Point, 4326)');
        
        // Recreate index
        DB::statement('DROP INDEX IF EXISTS lands_coordinates_idx');
        DB::statement('CREATE INDEX lands_coordinates_idx ON lands USING GIST (coordinates)');
    }
};