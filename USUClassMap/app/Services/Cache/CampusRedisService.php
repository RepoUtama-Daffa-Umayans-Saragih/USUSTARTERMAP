<?php

namespace App\Services\Cache;

use App\Models\Building;
use App\Models\PhotocopyPlace;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CampusRedisService
{
    public const KEY_GEO_PLACES = 'usu:geo:places';
    public const KEY_GEO_BUILDINGS = 'usu:geo:buildings';
    public const KEY_ROUTING_COMPILED = 'usu_campus_compiled_graph_v1';
    public const KEY_ROUTING_COMPILED_V2 = 'usu:routing:compiled_graph:v1';
    public const KEY_BUILDINGS_ALL_WIB = 'usu:buildings:all_wib';
    public const KEY_PHOTOCOPY_DIRECTORY = 'usu:photocopy:directory_active';
    public const KEY_FIB_WALK_GRAPH = 'usu:fib_walk:graph_v2';

    /** @var array<string, bool> */
    protected static array $heldOrderLocks = [];

    public function syncPlacesGeo(): int
    {
        $places = PhotocopyPlace::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude', 'entrance_lat', 'entrance_lng']);

        $args = [];
        foreach ($places as $place) {
            $lat = (float) ($place->entrance_lat ?? $place->latitude);
            $lng = (float) ($place->entrance_lng ?? $place->longitude);
            if ($lat < -11 || $lat > 6 || $lng < 95 || $lng > 141) {
                continue;
            }
            $args[] = $lng;
            $args[] = $lat;
            $args[] = (string) $place->id;
        }

        try {
            $conn = Redis::connection('default');
            $conn->command('DEL', [self::KEY_GEO_PLACES]);
            if (!empty($args)) {
                $conn->command('GEOADD', array_merge([self::KEY_GEO_PLACES], $args));
            }
        } catch (\Throwable $e) {
            Log::warning('CampusRedisService::syncPlacesGeo skipped: ' . $e->getMessage());
            return 0;
        }

        return (int) (count($args) / 3);
    }

    public function syncBuildingsGeo(): int
    {
        $buildings = Building::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'latitude', 'longitude', 'entrance_lat', 'entrance_lng']);

        $args = [];
        foreach ($buildings as $building) {
            $lat = (float) ($building->entrance_lat ?? $building->latitude);
            $lng = (float) ($building->entrance_lng ?? $building->longitude);
            if ($lat < -11 || $lat > 6 || $lng < 95 || $lng > 141) {
                continue;
            }
            $args[] = $lng;
            $args[] = $lat;
            $args[] = (string) $building->id;
        }

        try {
            $conn = Redis::connection('default');
            $conn->command('DEL', [self::KEY_GEO_BUILDINGS]);
            if (!empty($args)) {
                $conn->command('GEOADD', array_merge([self::KEY_GEO_BUILDINGS], $args));
            }
        } catch (\Throwable $e) {
            Log::warning('CampusRedisService::syncBuildingsGeo skipped: ' . $e->getMessage());
            return 0;
        }

        return (int) (count($args) / 3);
    }

    public function addGeoPoint(string $key, int|string $memberId, float $lat, float $lng): bool
    {
        try {
            Redis::connection('default')->command('GEOADD', [$key, $lng, $lat, (string) $memberId]);
            return true;
        } catch (\Throwable $e) {
            Log::warning("CampusRedisService::addGeoPoint({$key}) skipped: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @return array<int, int>
     */
    public function findNearbyPlaceIds(float $lat, float $lng, int $radiusMeters = 3000, int $limit = 50): array
    {
        return array_keys($this->findNearbyWithDistances(self::KEY_GEO_PLACES, $lat, $lng, $radiusMeters, $limit));
    }

    /**
     * @return array<int, int>
     */
    public function findNearbyBuildingIds(float $lat, float $lng, int $radiusMeters = 1500, int $limit = 10): array
    {
        return array_keys($this->findNearbyWithDistances(self::KEY_GEO_BUILDINGS, $lat, $lng, $radiusMeters, $limit));
    }

    /**
     * @return array<int, float>
     */
    public function findNearbyWithDistances(
        string $key,
        float $lat,
        float $lng,
        int $radiusMeters,
        int $limit
    ): array {
        try {
            $conn = Redis::connection('default');
            $client = $conn->client();
            $prefix = (string) config('database.redis.options.prefix', '');

            if (method_exists($client, 'executeRaw')) {
                $raw = $client->executeRaw([
                    'GEOSEARCH',
                    $prefix . $key,
                    'FROMLONLAT',
                    $lng,
                    $lat,
                    'BYRADIUS',
                    $radiusMeters,
                    'm',
                    'ASC',
                    'COUNT',
                    $limit,
                    'WITHDIST',
                ]);
            } else {
                $raw = $conn->command('GEORADIUS', [
                    $key,
                    $lng,
                    $lat,
                    $radiusMeters,
                    'm',
                    ['WITHDIST' => true, 'SORT' => 'ASC', 'COUNT' => $limit],
                ]);
            }

            if (!is_array($raw)) {
                return [];
            }

            $out = [];
            foreach ($raw as $entry) {
                if (is_array($entry) && isset($entry[0])) {
                    $out[(int) $entry[0]] = isset($entry[1]) ? (float) $entry[1] : 0.0;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning("CampusRedisService::findNearbyWithDistances({$key}) fallback empty: " . $e->getMessage());
            return [];
        }
    }

    public function invalidateBuildingCaches(): void
    {
        try {
            Cache::forget(self::KEY_BUILDINGS_ALL_WIB);
        } catch (\Throwable) {
        }
        $this->invalidateRoutingCache();
    }

    public function invalidatePhotocopyCaches(): void
    {
        try {
            Cache::forget(self::KEY_PHOTOCOPY_DIRECTORY);
        } catch (\Throwable) {
        }
    }

    public function invalidateRoutingCache(): void
    {
        try {
            Cache::forget(self::KEY_ROUTING_COMPILED);
            Cache::forget(self::KEY_ROUTING_COMPILED_V2);
        } catch (\Throwable) {
        }
    }

    public function invalidateVirtualWalkCache(): void
    {
        try {
            Cache::forget(self::KEY_FIB_WALK_GRAPH);
        } catch (\Throwable) {
        }
    }

    public function orderPaymentLock(string $orderCode, int $ttlSeconds = 10): Lock
    {
        return Cache::lock("usu:lock:order_pay:{$orderCode}", $ttlSeconds);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws LockTimeoutException
     */
    public function withOrderPaymentLock(
        string $orderCode,
        callable $callback,
        int $ttlSeconds = 10,
        int $blockSeconds = 5
    ): mixed {
        if (!empty(self::$heldOrderLocks[$orderCode])) {
            return $callback();
        }

        self::$heldOrderLocks[$orderCode] = true;

        try {
            $lock = $this->orderPaymentLock($orderCode, $ttlSeconds);

            if ($blockSeconds > 0) {
                return $lock->block($blockSeconds, $callback);
            }

            if (!$lock->get()) {
                throw new LockTimeoutException("Payment lock busy for order {$orderCode}.");
            }

            try {
                return $callback();
            } finally {
                $lock->release();
            }
        } finally {
            unset(self::$heldOrderLocks[$orderCode]);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withGitSyncLock(callable $callback, int $ttlSeconds = 30, int $blockSeconds = 5): mixed
    {
        try {
            $lock = Cache::lock('usu:lock:git_sync', $ttlSeconds);

            return $lock->block($blockSeconds, $callback);
        } catch (\Throwable $e) {
            Log::warning('CampusRedisService::withGitSyncLock fallback direct execution: ' . $e->getMessage());

            return $callback();
        }
    }
}
