<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Land extends Model
{
    use HasFactory;

    protected $fillable = [
        // ── Core ──────────────────────────────────────────────────────────
        'title', 'location', 'size', 'total_units', 'available_units',
        'is_available', 'description', 'coordinates', 'lat', 'lng',

        // ── Administrative ─────────────────────────────────────────────────
        'plot_identifier', 'tenure', 'lga', 'city', 'state',

        // ── Ownership & legal ──────────────────────────────────────────────
        'current_owner', 'dispute_status', 'taxation',
        'allocation_records', 'land_titles', 'historical_transactions',

        // ── Land use ───────────────────────────────────────────────────────
        'preexisting_landuse', 'current_landuse', 'proposed_landuse',
        'zoning', 'dev_control',

        // ── Geospatial & physical ──────────────────────────────────────────
        'slope', 'elevation', 'soil_type', 'bearing_capacity',
        'hydrology', 'vegetation',

        // ── Infrastructure & utilities ─────────────────────────────────────
        'road_type', 'road_category', 'road_condition',
        'electricity', 'water_supply', 'sewage', 'other_facilities',
        'comm_lines',

        // ── Valuation & fiscal ─────────────────────────────────────────────
        'overall_value', 'current_land_value', 'rental_pm', 'rental_pa',
    ];

    protected $attributes = [
        'total_units'     => 0,
        'available_units' => 0,
        'is_available'    => true,
    ];

    protected $casts = [
        // ── Core ──────────────────────────────────────────────────────────
        'size'            => 'float',
        'total_units'     => 'integer',
        'available_units' => 'integer',
        'is_available'    => 'boolean',

        // ── JSON columns ───────────────────────────────────────────────────
        'allocation_records'      => 'array',
        'land_titles'             => 'array',
        'historical_transactions' => 'array',
        'comm_lines'              => 'array',

        // ── Numeric ────────────────────────────────────────────────────────
        'slope'              => 'float',
        'elevation'          => 'float',
        'overall_value'      => 'float',
        'current_land_value' => 'float',
        'rental_pm'          => 'float',
        'rental_pa'          => 'float',
    ];

    protected $appends = [
        'units_sold',
        'sold_percentage',
        'map_color',
        'current_price_per_unit_kobo',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_land')
                    ->withPivot('units')
                    ->withTimestamps();
    }

    public function priceHistory()
    {
        return $this->hasMany(LandPriceHistory::class)->orderBy('price_date');
    }

    public function latestPrice()
    {
        return $this->hasOne(LandPriceHistory::class)
            ->where('price_date', '<=', now()->toDateString())
            ->orderByDesc('price_date')
            ->orderByDesc('created_at');
    }

    public function valuations()
    {
        return $this->hasMany(LandValuation::class)->orderBy('year');
    }

    public function latestValuation()
    {
        return $this->hasOne(LandValuation::class)->orderByDesc('year');
    }

    public function portfolioSnapshots()
    {
        return $this->hasMany(PortfolioLandSnapshot::class);
    }

    public function images()
    {
        return $this->hasMany(LandImage::class);
    }

    // ── Appended accessors ────────────────────────────────────────────────────

    public function getCurrentPricePerUnitKoboAttribute(): int
    {
        return $this->latestPrice?->price_per_unit_kobo ?? 0;
    }

    public function getUnitsSoldAttribute(): int
    {
        return (int) ($this->total_units ?? 0) - (int) ($this->available_units ?? 0);
    }

    public function getSoldPercentageAttribute(): float
    {
        $total = (int) ($this->total_units ?? 0);
        if ($total === 0) return 0.0;
        return round(($this->units_sold / $total) * 100, 2);
    }

    public function getMapColorAttribute(): string
    {
        return match (true) {
            $this->sold_percentage < 25 => 'green',
            $this->sold_percentage < 50 => 'yellow',
            $this->sold_percentage < 75 => 'orange',
            default                     => 'red',
        };
    }

    /**
     * Fetch GeoJSON on demand — NOT appended automatically to avoid N+1 queries.
     */
    public function getCoordinatesGeojson(): ?string
    {
        return DB::table('lands')
            ->where('id', $this->id)
            ->selectRaw('ST_AsGeoJSON(coordinates) as geojson')
            ->value('geojson');
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    public function scopeWithinBounds(
        Builder $query,
        float $north,
        float $south,
        float $east,
        float $west
    ): Builder {
        return $query->whereRaw(
            'coordinates && ST_MakeEnvelope(?, ?, ?, ?, 4326)',
            [$west, $south, $east, $north]
        );
    }
}