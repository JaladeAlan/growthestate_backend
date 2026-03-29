<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandPriceHistory;
use App\Models\LandValuation;
use App\Services\GeoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LandController extends Controller
{
    public function __construct(private GeoService $geo) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC
    // ─────────────────────────────────────────────────────────────────────────

    // GET /land  (public, cached)
    public function index(Request $request)
    {
        $lands = Cache::remember('lands.public', 300, fn () =>
            Land::with(['images', 'latestPrice'])
                ->where('is_available', true)
                ->orderByDesc('created_at')
                ->get()
        );

        return response()->json(['success' => true, 'data' => $lands]);
    }

    // GET /lands  (authenticated)
    public function indexAuth(Request $request)
    {
        $lands = Cache::remember('lands.auth', 300, fn () =>
            Land::with(['images', 'latestPrice'])
                ->orderByDesc('created_at')
                ->get()
        );

        return response()->json(['success' => true, 'data' => $lands]);
    }

    // GET /lands/map  (authenticated, bounding-box filtered)
    public function mapIndex(Request $request)
    {
        $request->validate([
            'min_lng' => 'required|numeric|between:-180,180',
            'min_lat' => 'required|numeric|between:-90,90',
            'max_lng' => 'required|numeric|between:-180,180',
            'max_lat' => 'required|numeric|between:-90,90',
        ]);

        try {
            $this->geo->validateBbox($request->only('min_lng', 'min_lat', 'max_lng', 'max_lat'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['bbox' => $e->getMessage()]);
        }

        $bboxExpr = $this->geo->makeBboxExpression(
            (float) $request->min_lng,
            (float) $request->min_lat,
            (float) $request->max_lng,
            (float) $request->max_lat
        );

        $lands = Land::with(['images', 'latestPrice'])
            ->whereNotNull('coordinates')
            ->whereRaw($bboxExpr)
            ->get(['id', 'title', 'lat', 'lng', 'available_units', 'is_available']);

        return response()->json(['success' => true, 'data' => $lands]);
    }

    // GET /lands/{id}
    public function show(Land $land)
    {
        return response()->json([
            'success' => true,
            'data'    => $land->load(['images', 'latestPrice', 'priceHistory', 'valuations']),
        ]);
    }

    // GET /lands/{id}/units
    public function units(Land $land)
    {
        $user = auth()->user();

        $userUnits = $user
            ? (int) $land->users()
                ->wherePivot('user_id', $user->id)
                ->sum('user_land.units')
            : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'total_units'     => $land->total_units,
                'available_units' => $land->available_units,
                'sold_units'      => $land->total_units - $land->available_units,
                'user_units'      => $userUnits,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN
    // ─────────────────────────────────────────────────────────────────────────

    // GET /admin/lands
    public function adminIndex()
    {
        $lands = Land::with(['images', 'latestPrice'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $lands]);
    }

    // POST /admin/lands
    public function store(Request $request)
    {
        $this->decodeJsonStrings($request, ['geometry', 'allocation_records', 'land_titles', 'historical_transactions', 'comm_lines', 'valuation_history']);

        $request->validate([
            ...$this->coreRules(),
            ...$this->detailRules(),
        ]);

        try {
            $this->geo->validateGeojson($request->geometry);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['geometry' => $e->getMessage()]);
        }

        [$centerLat, $centerLng, $wkt] = $this->resolveGeometry($request->geometry);

        $land = DB::transaction(function () use ($request, $wkt, $centerLat, $centerLng) {
            $land = Land::create([
                ...$this->extractCoreFields($request, $wkt, $centerLat, $centerLng),
                ...$this->extractDetailFields($request),
            ]);

            LandPriceHistory::create([
                'land_id'             => $land->id,
                'price_per_unit_kobo' => $request->price_per_unit_kobo,
                'price_date'          => today(),
            ]);

            $this->syncValuations($land, $request->input('valuation_history', []));

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('lands', 'public');
                    $land->images()->create(['image_path' => $path]);
                }
            }

            return $land;
        });

        $this->clearLandCache();

        return response()->json([
            'success' => true,
            'message' => 'Land created successfully.',
            'data'    => $land->load(['images', 'latestPrice', 'valuations']),
        ], 201);
    }

    // POST /admin/lands/{id}
    public function update(Request $request, Land $land)
    {
        $this->decodeJsonStrings($request, ['geometry', 'allocation_records', 'land_titles', 'historical_transactions', 'comm_lines', 'valuation_history']);

        $request->validate([
            ...$this->coreRules(creating: false),
            ...$this->detailRules(),
        ]);

        $updates = $this->extractDetailFields($request, onlyFilled: true);

        if ($request->has('geometry')) {
            try {
                $this->geo->validateGeojson($request->geometry);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['geometry' => $e->getMessage()]);
            }

            [$centerLat, $centerLng, $wkt] = $this->resolveGeometry($request->geometry);
            $updates['coordinates'] = DB::raw("ST_GeomFromText('{$wkt}', 4326)");
            $updates['lat']         = $centerLat;
            $updates['lng']         = $centerLng;
        }

        foreach (['title', 'location', 'size', 'description'] as $field) {
            if ($request->has($field)) $updates[$field] = $request->input($field);
        }

        DB::transaction(function () use ($request, $land, $updates) {
            $land->update($updates);

            if ($request->has('valuation_history')) {
                $this->syncValuations($land, $request->input('valuation_history', []));
            }

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('lands', 'public');
                    $land->images()->create(['image_path' => $path]);
                }
            }
        });

        $this->clearLandCache();

        return response()->json([
            'success' => true,
            'message' => 'Land updated.',
            'data'    => $land->fresh()->load(['images', 'latestPrice', 'valuations']),
        ]);
    }

    // PATCH /admin/lands/{id}/price
    public function updatePrice(Request $request, Land $land)
    {
        $request->validate([
            'price_per_unit_kobo' => 'required|integer|min:1',
            'price_date'          => 'sometimes|date|before_or_equal:today',
        ]);

        $priceDate = $request->input('price_date', today()->toDateString());

        $priceRecord = LandPriceHistory::updateOrCreate(
            ['land_id' => $land->id, 'price_date' => $priceDate],
            ['price_per_unit_kobo' => $request->price_per_unit_kobo]
        );

        event(new \App\Events\LandPriceChanged($land->id, $request->price_per_unit_kobo, $priceDate));

        $this->clearLandCache();

        return response()->json([
            'success' => true,
            'message' => 'Price updated.',
            'data'    => $priceRecord,
        ]);
    }

    // PATCH /admin/lands/{id}/availability
    public function toggleAvailability(Request $request, Land $land)
    {
        $land->update(['is_available' => ! $land->is_available]);

        $this->clearLandCache();

        return response()->json([
            'success'      => true,
            'is_available' => $land->is_available,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VALUATION HISTORY — each year+month is its own row in land_valuations
    // ─────────────────────────────────────────────────────────────────────────

    // GET /admin/lands/{id}/valuation
    public function getValuations(Land $land)
    {
        return response()->json([
            'success' => true,
            'data'    => $land->valuations()->orderBy('year')->orderBy('month')->get(),
        ]);
    }

    // POST /admin/lands/{id}/valuation
    public function addValuationEntry(Request $request, Land $land)
    {
        $request->validate([
            'year'  => 'required|integer|min:1900|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'value' => 'required|numeric|min:0',
        ]);

        $year  = (int) $request->year;
        $month = (int) $request->month;

        if ($land->valuations()->where('year', $year)->where('month', $month)->exists()) {
            throw ValidationException::withMessages([
                'month' => "A valuation entry for {$year}-{$month} already exists. Use the update endpoint to change it.",
            ]);
        }

        $entry = $land->valuations()->create([
            'year'  => $year,
            'month' => $month,
            'value' => $request->value,
        ]);

        $this->syncCurrentLandValue($land);
        $this->clearLandCache();

        return response()->json([
            'success' => true,
            'message' => "Valuation entry for {$year}-{$month} added.",
            'data'    => $entry,
        ], 201);
    }

    // PATCH /admin/lands/{id}/valuation/{year}/{month}
    public function updateValuationEntry(Request $request, Land $land, int $year, int $month)
    {
        $request->validate([
            'value' => 'required|numeric|min:0',
        ]);

        $entry = $land->valuations()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $entry) {
            throw ValidationException::withMessages([
                'month' => "No valuation entry found for {$year}-{$month}.",
            ]);
        }

        $entry->update(['value' => $request->value]);

        $this->syncCurrentLandValue($land);
        $this->clearLandCache();

        return response()->json([
            'success' => true,
            'message' => "Valuation entry for {$year}-{$month} updated.",
            'data'    => $entry->fresh(),
        ]);
    }

    // DELETE /admin/lands/{id}/valuation/{year}/{month}
    public function deleteValuationEntry(Land $land, int $year, int $month)
    {
        $deleted = $land->valuations()
            ->where('year', $year)
            ->where('month', $month)
            ->delete();

        if (! $deleted) {
            throw ValidationException::withMessages([
                'month' => "No valuation entry found for {$year}-{$month}.",
            ]);
        }

        $this->syncCurrentLandValue($land);
        $this->clearLandCache();

        return response()->json([
            'success' => true,
            'message' => "Valuation entry for {$year}-{$month} removed.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Decode named fields from JSON strings — needed for multipart/form-data.
     */
    private function decodeJsonStrings(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if ($request->has($field) && is_string($request->input($field))) {
                $decoded = json_decode($request->input($field), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([$field => $decoded]);
                }
            }
        }
    }

    /**
     * Resolve a GeoJSON geometry into [lat, lng, wkt].
     */
    private function resolveGeometry(array $geometry): array
    {
        $wkt = $this->geo->toWkt($geometry);

        if ($geometry['type'] === 'Polygon') {
            $centroid = $this->geo->polygonCentroid($geometry['coordinates']);
            return [$centroid['lat'], $centroid['lng'], $wkt];
        }

        return [$geometry['coordinates'][1], $geometry['coordinates'][0], $wkt];
    }

    /**
     * Extract core land fields for Land::create().
     */
    private function extractCoreFields(Request $request, string $wkt, ?float $lat, ?float $lng): array
    {
        return [
            'title'           => $request->title,
            'location'        => $request->location,
            'size'            => $request->size,
            'total_units'     => $request->total_units,
            'available_units' => $request->total_units,
            'description'     => $request->description,
            'lat'             => $lat,
            'lng'             => $lng,
            'coordinates'     => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
            'is_available'    => true,
        ];
    }

    private function extractDetailFields(Request $request, bool $onlyFilled = false): array
    {
        $fields = [
            // Administrative
            'plot_identifier', 'tenure', 'lga', 'city', 'state',
            // Ownership & legal
            'current_owner', 'dispute_status', 'taxation',
            'allocation_records', 'land_titles', 'historical_transactions',
            // Land use
            'preexisting_landuse', 'current_landuse', 'proposed_landuse',
            'zoning', 'dev_control',
            // Geospatial & physical
            'slope', 'elevation', 'soil_type', 'bearing_capacity',
            'hydrology', 'vegetation',
            // Infrastructure & utilities
            'road_type', 'road_category', 'road_condition',
            'electricity', 'water_supply', 'sewage', 'other_facilities',
            'comm_lines',
            // Valuation & fiscal
            'overall_value', 'current_land_value', 'rental_pm', 'rental_pa',
        ];

        $extracted = [];
        foreach ($fields as $field) {
            if ($onlyFilled) {
                if ($request->has($field)) {
                    $extracted[$field] = $request->input($field);
                }
            } else {
                $extracted[$field] = $request->input($field);
            }
        }

        return $extracted;
    }

    /**
     * Upsert valuation history rows into land_valuations.
     * Accepts [[year, month, value], ...] format from the request.
     */
    private function syncValuations(Land $land, array $history): void
    {
        foreach ($history as $entry) {
            [$year, $month, $value] = $entry;
            $land->valuations()->updateOrCreate(
                ['year' => (int) $year, 'month' => (int) $month],
                ['value' => (float) $value]
            );
        }

        $this->syncCurrentLandValue($land);
    }

    /**
     * Keep current_land_value in sync with the most recent land_valuations row
     * (ordered by year desc, then month desc).
     */
    private function syncCurrentLandValue(Land $land): void
    {
        $latest = $land->valuations()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $land->updateQuietly([
            'current_land_value' => $latest?->value,
        ]);
    }

    /**
     * Validation rules for core land fields.
     */
    private function coreRules(bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title'               => "{$req}|string|max:200",
            'location'            => "{$req}|string|max:200",
            'size'                => "{$req}|numeric|min:1",
            'total_units'         => "{$req}|integer|min:1",
            'description'         => 'nullable|string|max:2000',
            'geometry'            => "{$req}|array",
            'geometry.type'       => ($creating ? 'required' : 'required_with:geometry') . '|in:Point,Polygon',
            'price_per_unit_kobo' => "{$req}|integer|min:1",
            'images'              => 'nullable|array|max:10',
            'images.*'            => 'image|max:5120',
        ];
    }

    /**
     * Validation rules for all detail fields.
     */
    private function detailRules(): array
    {
        return [
            // Administrative
            'plot_identifier' => 'nullable|string|max:300',
            'tenure'          => 'nullable|string|max:100',
            'lga'             => 'nullable|string|max:100',
            'city'            => 'nullable|string|max:100',
            'state'           => 'nullable|string|max:100',

            // Ownership & legal
            'current_owner'           => 'nullable|string|max:200',
            'dispute_status'          => 'nullable|string|max:200',
            'taxation'                => 'nullable|string|max:200',
            'allocation_records'      => 'nullable|array',
            'land_titles'             => 'nullable|array',
            'historical_transactions' => 'nullable|array',

            // Land use
            'preexisting_landuse' => 'nullable|string|max:100',
            'current_landuse'     => 'nullable|string|max:100',
            'proposed_landuse'    => 'nullable|string|max:100',
            'zoning'              => 'nullable|string|max:200',
            'dev_control'         => 'nullable|string|max:200',

            // Geospatial & physical
            'slope'            => 'nullable|numeric',
            'elevation'        => 'nullable|numeric',
            'soil_type'        => 'nullable|string|max:100',
            'bearing_capacity' => 'nullable|string|max:100',
            'hydrology'        => 'nullable|string|max:100',
            'vegetation'       => 'nullable|string|max:100',

            // Infrastructure & utilities
            'road_type'        => 'nullable|string|max:100',
            'road_category'    => 'nullable|string|max:100',
            'road_condition'   => 'nullable|string|max:100',
            'electricity'      => 'nullable|string|max:100',
            'water_supply'     => 'nullable|string|max:100',
            'sewage'           => 'nullable|string|max:100',
            'other_facilities' => 'nullable|string|max:300',
            'comm_lines'       => 'nullable|array',
            'comm_lines.*.0'   => 'nullable|string|max:50',
            'comm_lines.*.1'   => 'nullable|integer|min:0|max:100',

            // Valuation & fiscal
            'overall_value'      => 'nullable|numeric',
            'current_land_value' => 'nullable|numeric',
            'rental_pm'          => 'nullable|numeric',
            'rental_pa'          => 'nullable|numeric',

            // Valuation history — [[year, month, value], ...]
            'valuation_history'       => 'nullable|array',
            'valuation_history.*.0'   => 'nullable|integer|min:1900|max:2100', // year
            'valuation_history.*.1'   => 'nullable|integer|min:1|max:12',      // month
            'valuation_history.*.2'   => 'nullable|numeric|min:0',             // value
        ];
    }

    /**
     * Flush both land caches.
     */
    private function clearLandCache(): void
    {
        Cache::forget('lands.public');
        Cache::forget('lands.auth');
    }
}