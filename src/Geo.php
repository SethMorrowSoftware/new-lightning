<?php
/**
 * Great-circle helpers. Distances are in statute miles, which is what the
 * lightning-safety guidance venues follow is written in.
 */
declare(strict_types=1);

namespace StormWatch;

final class Geo
{
    /** Mean earth radius in statute miles. */
    public const EARTH_RADIUS_MI = 3958.8;

    public const MILES_TO_METRES = 1609.344;

    public const MILES_TO_KM = 1.609344;

    public static function distanceMiles(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return self::EARTH_RADIUS_MI * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /** Initial bearing from point 1 to point 2, in degrees clockwise from north. */
    public static function bearingDegrees(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1r = deg2rad($lat1);
        $lat2r = deg2rad($lat2);
        $dLon = deg2rad($lon2 - $lon1);
        $y = sin($dLon) * cos($lat2r);
        $x = cos($lat1r) * sin($lat2r) - sin($lat1r) * cos($lat2r) * cos($dLon);
        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /** @return array{lat:float,lon:float} */
    public static function destination(float $lat, float $lon, float $bearingDeg, float $distanceMi): array
    {
        $angular = $distanceMi / self::EARTH_RADIUS_MI;
        $bearing = deg2rad($bearingDeg);
        $lat1 = deg2rad($lat);
        $lon1 = deg2rad($lon);
        $lat2 = asin(sin($lat1) * cos($angular) + cos($lat1) * sin($angular) * cos($bearing));
        $lon2 = $lon1 + atan2(
            sin($bearing) * sin($angular) * cos($lat1),
            cos($angular) - sin($lat1) * sin($lat2)
        );
        return [
            'lat' => rad2deg($lat2),
            'lon' => fmod(rad2deg($lon2) + 540.0, 360.0) - 180.0,
        ];
    }

    public static function compassPoint(float $bearingDeg): string
    {
        $points = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $index = (int) round(fmod($bearingDeg + 360.0, 360.0) / 22.5) % 16;
        return $points[$index];
    }

    public static function isValidLatitude(float $lat): bool
    {
        return $lat >= -90.0 && $lat <= 90.0;
    }

    public static function isValidLongitude(float $lon): bool
    {
        return $lon >= -180.0 && $lon <= 180.0;
    }

    /**
     * A bounding box big enough to contain a radius around a point. Used to
     * pre-filter feed records cheaply before doing the haversine.
     *
     * @return array{minLat:float,maxLat:float,minLon:float,maxLon:float}
     */
    public static function boundingBox(float $lat, float $lon, float $radiusMi): array
    {
        $latDelta = $radiusMi / 69.0;
        $cos = cos(deg2rad($lat));
        $lonDelta = abs($cos) < 1e-6 ? 180.0 : $radiusMi / (69.0 * $cos);
        return [
            'minLat' => $lat - $latDelta,
            'maxLat' => $lat + $latDelta,
            'minLon' => $lon - abs($lonDelta),
            'maxLon' => $lon + abs($lonDelta),
        ];
    }

    public static function formatDistance(float $miles, string $units): string
    {
        if ($units === 'km') {
            return number_format($miles * self::MILES_TO_KM, 1) . ' km';
        }
        return number_format($miles, 1) . ' mi';
    }
}
