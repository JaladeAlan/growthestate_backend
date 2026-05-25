<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * GeoService
 *
 * Centralises all geospatial helpers previously scattered inside LandController.
 * Handles:
 *  - GeoJSON → WKT conversion (Point and Polygon)
 *  - Polygon centroid calculation
 *  - Bounding-box query string generation for PostGIS
 *  - Coordinate validation
 */
class GeoService
{
    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert a GeoJSON geometry array to a PostGIS WKT string.
     *
     * Supported types: Point, Polygon.
     *
     * @param  array  $geojson  Decoded GeoJSON geometry object
     * @return string           WKT e.g. "POINT(3.3792 6.5244)" or "POLYGON((...))"
     *
     * @throws \InvalidArgumentException for unsupported geometry types or bad coordinates
     */
    public function toWkt(array $geojson): string
    {
        $type = Arr::get($geojson, 'type');

        return match ($type) {
            'Point'   => $this->pointToWkt($geojson['coordinates']),
            'Polygon' => $this->polygonToWkt($geojson['coordinates']),
            default   => throw new \InvalidArgumentException("Unsupported geometry type: {$type}"),
        };
    }

    /**
     * Compute the centroid (lat, lng) of a GeoJSON Polygon.
     *
     * Uses the simple average of all unique outer ring vertices.
     * GeoJSON rings repeat the first vertex at the end to close the ring;
     * that duplicate is excluded before averaging to avoid biasing the result
     * toward the first vertex.
     *
     * For large or heavily irregular polygons consider offloading to
     * PostGIS ST_Centroid instead.
     *
     * @param  array  $polygonCoords  GeoJSON coordinate array  [ [ [lng,lat], … ] ]
     * @return array{lat: float, lng: float}
     *
     * @throws \InvalidArgumentException
     */
    public function polygonCentroid(array $polygonCoords): array
    {
        $outerRing = $polygonCoords[0] ?? [];

        if (empty($outerRing)) {
            throw new \InvalidArgumentException('Polygon outer ring is empty.');
        }

        if (count($outerRing) > 1 && $outerRing[0] === $outerRing[count($outerRing) - 1]) {
            array_pop($outerRing);
        }

        $count  = count($outerRing);
        $sumLng = array_sum(array_column($outerRing, 0));
        $sumLat = array_sum(array_column($outerRing, 1));

        return [
            'lat' => $sumLat / $count,
            'lng' => $sumLng / $count,
        ];
    }

    /**
     * Build a PostGIS expression that matches geometries overlapping a
     * bounding box. Used inside whereRaw() calls.
     *
     * @param  float  $minLng  West
     * @param  float  $minLat  South
     * @param  float  $maxLng  East
     * @param  float  $maxLat  North
     * @param  int    $srid    Spatial reference ID (default 4326 = WGS-84)
     * @return string          Raw SQL fragment safe for use in whereRaw()
     */
    public function makeBboxExpression(
        float $minLng,
        float $minLat,
        float $maxLng,
        float $maxLat,
        int   $srid = 4326
    ): string {
        return "ST_Intersects(coordinates, ST_MakeEnvelope({$minLng}, {$minLat}, {$maxLng}, {$maxLat}, {$srid}))";
    }

    /**
     * Validate a bounding-box parameter set.
     *
     * @param  array  $params  Associative array with keys: min_lng, min_lat, max_lng, max_lat
     * @throws \InvalidArgumentException
     */
    public function validateBbox(array $params): void
    {
        foreach (['min_lng', 'min_lat', 'max_lng', 'max_lat'] as $key) {
            if (! isset($params[$key]) || ! is_numeric($params[$key])) {
                throw new \InvalidArgumentException("Missing or non-numeric bounding box parameter: {$key}");
            }
        }

        if ((float) $params['min_lng'] >= (float) $params['max_lng']) {
            throw new \InvalidArgumentException('min_lng must be less than max_lng.');
        }

        if ((float) $params['min_lat'] >= (float) $params['max_lat']) {
            throw new \InvalidArgumentException('min_lat must be less than max_lat.');
        }
    }

    /**
     * Validate a GeoJSON geometry array (lightweight — does not call PostGIS).
     *
     * @throws \InvalidArgumentException
     */
    public function validateGeojson(array $geojson): void
    {
        $type = Arr::get($geojson, 'type');

        if (! in_array($type, ['Point', 'Polygon'], true)) {
            throw new \InvalidArgumentException("geometry.type must be 'Point' or 'Polygon', got: {$type}");
        }

        $coords = Arr::get($geojson, 'coordinates');

        if ($type === 'Point') {
            if (! is_array($coords) || count($coords) < 2) {
                throw new \InvalidArgumentException('Point coordinates must be [lng, lat].');
            }
            $this->assertLngLat((float) $coords[0], (float) $coords[1]);
        }

        if ($type === 'Polygon') {
            if (! is_array($coords) || empty($coords[0])) {
                throw new \InvalidArgumentException('Polygon must have at least one ring.');
            }

            foreach ($coords as $ringIndex => $ring) {
                if (! is_array($ring) || empty($ring)) {
                    throw new \InvalidArgumentException("Polygon ring {$ringIndex} is empty.");
                }
                foreach ($ring as $vertex) {
                    if (! is_array($vertex) || count($vertex) < 2) {
                        throw new \InvalidArgumentException(
                            "Ring {$ringIndex} contains a vertex with fewer than 2 coordinates."
                        );
                    }
                    $this->assertLngLat((float) $vertex[0], (float) $vertex[1]);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function pointToWkt(array $coords): string
    {
        [$lng, $lat] = $coords;
        $this->assertLngLat((float) $lng, (float) $lat);

        return "POINT({$lng} {$lat})";
    }

    /**
     * Convert a GeoJSON coordinate ring array to a WKT POLYGON string.
     */
    private function polygonToWkt(array $rings): string
    {
        $ringStrings = array_map(function (array $ring) {
            // Close the ring if the client omitted the closing vertex.
            if (! empty($ring) && $ring[0] !== end($ring)) {
                $ring[] = $ring[0];
            }

            $vertices = array_map(fn ($v) => "{$v[0]} {$v[1]}", $ring);

            return '(' . implode(', ', $vertices) . ')';
        }, $rings);

        return 'POLYGON(' . implode(', ', $ringStrings) . ')';
    }

    private function assertLngLat(float $lng, float $lat): void
    {
        if ($lng < -180 || $lng > 180) {
            throw new \InvalidArgumentException("Longitude {$lng} is out of range [-180, 180].");
        }
        if ($lat < -90 || $lat > 90) {
            throw new \InvalidArgumentException("Latitude {$lat} is out of range [-90, 90].");
        }
    }
}