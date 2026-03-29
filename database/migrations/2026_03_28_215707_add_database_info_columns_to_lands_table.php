<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lands', function (Blueprint $table) {

            // ── Administrative ────────────────────────────────────────────
            $table->string('plot_identifier',  300)->nullable()->after('description');
            $table->string('tenure',           100)->nullable()->after('plot_identifier');
            $table->string('lga',              100)->nullable()->after('tenure');
            $table->string('city',             100)->nullable()->after('lga');
            $table->string('state',            100)->nullable()->after('city');

            // ── Ownership & legal ─────────────────────────────────────────
            $table->string('current_owner',    200)->nullable()->after('state');
            $table->string('dispute_status',   200)->nullable()->after('current_owner');
            $table->string('taxation',         200)->nullable()->after('dispute_status');
            $table->json('allocation_records')     ->nullable()->after('taxation');
            $table->json('land_titles')            ->nullable()->after('allocation_records');
            $table->json('historical_transactions')->nullable()->after('land_titles');

            // ── Land use ──────────────────────────────────────────────────
            $table->string('preexisting_landuse', 100)->nullable()->after('historical_transactions');
            $table->string('current_landuse',     100)->nullable()->after('preexisting_landuse');
            $table->string('proposed_landuse',    100)->nullable()->after('current_landuse');
            $table->string('zoning',              200)->nullable()->after('proposed_landuse');
            $table->string('dev_control',         200)->nullable()->after('zoning');

            // ── Geospatial & physical ─────────────────────────────────────
            $table->decimal('slope',    5, 2)->nullable()->after('dev_control');
            $table->decimal('elevation',8, 2)->nullable()->after('slope');
            $table->string('soil_type',      100)->nullable()->after('elevation');
            $table->string('bearing_capacity',100)->nullable()->after('soil_type');
            $table->string('hydrology',      100)->nullable()->after('bearing_capacity');
            $table->string('vegetation',     100)->nullable()->after('hydrology');

            // ── Infrastructure & utilities ────────────────────────────────
            $table->string('road_type',      100)->nullable()->after('vegetation');
            $table->string('road_category',  100)->nullable()->after('road_type');
            $table->string('road_condition', 100)->nullable()->after('road_category');
            $table->string('electricity',    100)->nullable()->after('road_condition');
            $table->string('water_supply',   100)->nullable()->after('electricity');
            $table->string('sewage',         100)->nullable()->after('water_supply');
            $table->string('other_facilities',300)->nullable()->after('sewage');
            $table->json('comm_lines')            ->nullable()->after('other_facilities');

            // ── Valuation & fiscal ────────────────────────────────────────
            $table->decimal('overall_value',     15, 2)->nullable()->after('comm_lines');
            $table->decimal('current_land_value',15, 2)->nullable()->after('overall_value');
            $table->decimal('rental_pm',         15, 2)->nullable()->after('current_land_value');
            $table->decimal('rental_pa',         15, 2)->nullable()->after('rental_pm');
        });
    }

    public function down(): void
    {
        Schema::table('lands', function (Blueprint $table) {
            $table->dropColumn([
                'plot_identifier', 'tenure', 'lga', 'city', 'state',
                'current_owner', 'dispute_status', 'taxation',
                'allocation_records', 'land_titles', 'historical_transactions',
                'preexisting_landuse', 'current_landuse', 'proposed_landuse',
                'zoning', 'dev_control',
                'slope', 'elevation', 'soil_type', 'bearing_capacity',
                'hydrology', 'vegetation',
                'road_type', 'road_category', 'road_condition',
                'electricity', 'water_supply', 'sewage', 'other_facilities',
                'comm_lines',
                'overall_value', 'current_land_value', 'rental_pm', 'rental_pa',
            ]);
        });
    }
};