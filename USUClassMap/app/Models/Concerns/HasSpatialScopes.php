<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasSpatialScopes
{
    public function scopeNearby(Builder $query, float $lat, float $lng, int $radiusMeters = 3000): Builder
    {
        $table = $query->getModel()->getTable();

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->whereRaw(
                "COALESCE({$table}.entrance_geom, {$table}.geom) IS NOT NULL AND ST_DWithin(COALESCE({$table}.entrance_geom, {$table}.geom), ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)",
                [$lng, $lat, $radiusMeters]
            );
        }

        $latDelta = $radiusMeters / 111320.0;
        $lngDelta = $radiusMeters / (111320.0 * max(0.1, abs(cos(deg2rad($lat)))));

        return $query
            ->whereBetween("{$table}.latitude", [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween("{$table}.longitude", [$lng - $lngDelta, $lng + $lngDelta]);
    }

    public function scopeWithDistanceMeters(Builder $query, ?float $lat, ?float $lng): Builder
    {
        if ($lat === null || $lng === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        if (empty($query->getQuery()->columns)) {
            $query->select("{$table}.*");
        }

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            return $query->selectRaw(
                "ROUND(ST_Distance(COALESCE({$table}.entrance_geom, {$table}.geom), ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography))::INTEGER AS distance_meters",
                [$lng, $lat]
            );
        }

        $cosFactor = cos(deg2rad($lat)) ** 2;

        return $query->selectRaw(
            "CAST(ROUND(111320.0 * (((COALESCE({$table}.entrance_lat, {$table}.latitude) - ?) * (COALESCE({$table}.entrance_lat, {$table}.latitude) - ?)) + ((COALESCE({$table}.entrance_lng, {$table}.longitude) - ?) * (COALESCE({$table}.entrance_lng, {$table}.longitude) - ?) * ?))) AS INTEGER) AS distance_meters",
            [$lat, $lat, $lng, $lng, $cosFactor]
        );
    }
}
