<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LandPriceHistory;
use App\Services\GeoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LandController
 *
 */
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
            'data'    => $land->load(['images', 'latestPrice', 'priceHistory']),
        ]);
    }

    // GET /lands/{id}/units
    public function units(Land $land)
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'total_units'     => $land->total_units,
                'available_units' => $land->available_units,
                'sold_units'      => $land->total_units - $land->available_units,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN
    // ─────────────────────────────────────────────────────────────────────────

    // POST /admin/lands
    public function store(Request $request)
    {
        $request->validate([
            'title'        => 'required|string|max:200',
            'location'     => 'required|string|max:200',
            'size'         => 'required|numeric|min:1',
            'total_units'  => 'required|integer|min:1',
            'description'  => 'nullable|string|max:2000',
            'geometry'     => 'required|array',
            'geometry.type'=> 'required|in:Point,Polygon',
            'price_per_unit_kobo' => 'required|integer|min:1',
            'images'       => 'nullable|array|max:10',
            'images.*'     => 'image|max:5120',
        ]);

        try {
            $this->geo->validateGeojson($request->geometry);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['geometry' => $e->getMessage()]);
        }

        $wkt       = $this->geo->toWkt($request->geometry);
        $centerLat = null;
        $centerLng = null;

        if ($request->geometry['type'] === 'Polygon') {
            $centroid  = $this->geo->polygonCentroid($request->geometry['coordinates']);
            $centerLat = $centroid['lat'];
            $centerLng = $centroid['lng'];
        } else {
            [$centerLng, $centerLat] = $request->geometry['coordinates'];
        }

        $land = DB::transaction(function () use ($request, $wkt, $centerLat, $centerLng) {
            $land = Land::create([
                'title'           => $request->title,
                'location'        => $request->location,
                'size'            => $request->size,
                'total_units'     => $request->total_units,
                'available_units' => $request->total_units,
                'description'     => $request->description,
                'lat'             => $centerLat,
                'lng'             => $centerLng,
                'coordinates'     => DB::raw("ST_GeomFromText('{$wkt}', 4326)"),
                'is_available'    => true,
            ]);

            LandPriceHistory::create([
                'land_id'             => $land->id,
                'price_per_unit_kobo' => $request->price_per_unit_kobo,
                'price_date'          => today(),
            ]);

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('lands', 'public');
                    $land->images()->create(['image_path' => $path]);
                }
            }

            return $land;
        });

        Cache::forget('lands.public');
        Cache::forget('lands.auth');

        return response()->json([
            'success' => true,
            'message' => 'Land created successfully.',
            'data'    => $land->load(['images', 'latestPrice']),
        ], 201);
    }

    // POST /admin/lands/{id}  (POST used for multipart/form-data compatibility)
    public function update(Request $request, Land $land)
    {
        $request->validate([
            'title'       => 'sometimes|string|max:200',
            'location'    => 'sometimes|string|max:200',
            'size'        => 'sometimes|numeric|min:1',
            'description' => 'nullable|string|max:2000',
            'geometry'    => 'sometimes|array',
            'images'      => 'nullable|array|max:10',
            'images.*'    => 'image|max:5120',
        ]);

        $updates = $request->only('title', 'location', 'size', 'description');

        if ($request->has('geometry')) {
            try {
                $this->geo->validateGeojson($request->geometry);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['geometry' => $e->getMessage()]);
            }

            $wkt = $this->geo->toWkt($request->geometry);
            $updates['coordinates'] = DB::raw("ST_GeomFromText('{$wkt}', 4326)");

            if ($request->geometry['type'] === 'Polygon') {
                $centroid          = $this->geo->polygonCentroid($request->geometry['coordinates']);
                $updates['lat']    = $centroid['lat'];
                $updates['lng']    = $centroid['lng'];
            } else {
                $updates['lng'] = $request->geometry['coordinates'][0];
                $updates['lat'] = $request->geometry['coordinates'][1];
            }
        }

        DB::transaction(function () use ($request, $land, $updates) {
            $land->update($updates);

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('lands', 'public');
                    $land->images()->create(['image_path' => $path]);
                }
            }
        });

        Cache::forget('lands.public');
        Cache::forget('lands.auth');

        return response()->json([
            'success' => true,
            'message' => 'Land updated.',
            'data'    => $land->fresh()->load(['images', 'latestPrice']),
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

        Cache::forget('lands.public');
        Cache::forget('lands.auth');

        return response()->json([
            'success' => true,
            'message' => 'Price updated.',
            'data'    => $priceRecord,
        ]);
    }

    // PATCH /admin/lands/{id}/availability  (enable / disable)
    public function toggleAvailability(Request $request, Land $land)
    {
        $land->update(['is_available' => ! $land->is_available]);

        Cache::forget('lands.public');
        Cache::forget('lands.auth');

        return response()->json([
            'success'      => true,
            'is_available' => $land->is_available,
        ]);
    }

    // GET /admin/lands
    public function adminIndex()
    {
        $lands = Land::with(['images', 'latestPrice'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $lands]);
    }
}