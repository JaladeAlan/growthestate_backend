<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

        Schema::table('lands', function (Blueprint $table) {
            $table->geometry('coordinates_new', 'POINT', 4326)->nullable();
        });

        DB::statement("
            UPDATE lands
            SET coordinates_new = ST_SetSRID(
                ST_MakePoint(
                    coordinates[1],
                    coordinates[2]
                ),
                4326
            )
            WHERE coordinates IS NOT NULL
        ");

        Schema::table('lands', function (Blueprint $table) {
            $table->dropColumn('coordinates');
            $table->renameColumn('coordinates_new', 'coordinates');
        });
    }

    public function down()
    {
        Schema::table('lands', function (Blueprint $table) {
            $table->float('coordinates_old', 2)->nullable();
        });

        DB::statement("
            UPDATE lands
            SET coordinates_old = ARRAY[
                ST_X(coordinates),
                ST_Y(coordinates)
            ]
            WHERE coordinates IS NOT NULL
        ");

        Schema::table('lands', function (Blueprint $table) {
            $table->dropColumn('coordinates');
            $table->renameColumn('coordinates_old', 'coordinates');
        });
    }
};
