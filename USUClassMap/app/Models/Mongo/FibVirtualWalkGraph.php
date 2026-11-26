<?php

namespace App\Models\Mongo;

use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

class FibVirtualWalkGraph extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'fib_walk_graphs';

    protected $fillable = [
        'version',
        'start_scene_id',
        'scenes',
        'links',
        'json_sha1',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'start_scene_id' => 'integer',
        ];
    }

    /**
     * Convert raw fib_walk_graph JSON structure into BSON document payload.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function fromRawGraph(array $raw, ?string $jsonSha1 = null): array
    {
        $scenesList = [];
        $links = [];

        foreach ($raw['scenes'] ?? [] as $key => $scene) {
            if (!is_array($scene)) {
                continue;
            }

            $sceneId = (int) ($scene['id'] ?? $key);
            $normalizedScene = $scene;
            $normalizedScene['id'] = $sceneId;

            if (
                isset($scene['lat'], $scene['lng'])
                && is_numeric($scene['lat'])
                && is_numeric($scene['lng'])
            ) {
                $lat = (float) $scene['lat'];
                $lng = (float) $scene['lng'];
                if ($lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0) {
                    $normalizedScene['location'] = [
                        'type' => 'Point',
                        'coordinates' => [$lng, $lat],
                    ];
                }
            } elseif (
                isset($scene['location']['type'], $scene['location']['coordinates'])
                && $scene['location']['type'] === 'Point'
                && is_array($scene['location']['coordinates'])
                && count($scene['location']['coordinates']) === 2
            ) {
                $normalizedScene['location'] = $scene['location'];
            } else {
                unset($normalizedScene['location']);
            }

            $scenesList[] = $normalizedScene;

            foreach ($scene['hotspots'] ?? [] as $hs) {
                if (!is_array($hs) || empty($hs['target_id'])) {
                    continue;
                }
                $links[] = [
                    'from_id' => $sceneId,
                    'target_id' => (int) $hs['target_id'],
                    'kind' => (string) ($hs['kind'] ?? 'walk'),
                    'yaw' => (float) ($hs['yaw'] ?? 0.0),
                    'pitch' => (float) ($hs['pitch'] ?? 0.0),
                ];
            }
        }

        $updatedAt = $raw['updated_at'] ?? null;
        if (is_string($updatedAt) && $updatedAt !== '') {
            try {
                $updatedAt = Carbon::parse($updatedAt);
            } catch (\Throwable) {
                $updatedAt = now();
            }
        } elseif (!$updatedAt instanceof \DateTimeInterface) {
            $updatedAt = now();
        }

        return [
            'version' => (int) ($raw['version'] ?? 2),
            'start_scene_id' => (int) ($raw['start_scene_id'] ?? 495),
            'scenes' => $scenesList,
            'links' => $links,
            'json_sha1' => $jsonSha1,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Reconstruct raw associative scenes array expected by FibVirtualWalkGraphService.
     *
     * @return array<string, mixed>
     */
    public function toRawGraph(): array
    {
        $scenesMap = [];
        foreach ($this->scenes ?? [] as $key => $scene) {
            if (!is_array($scene)) {
                continue;
            }
            $id = (int) ($scene['id'] ?? $key);
            unset($scene['location']);
            $scenesMap[(string) $id] = $scene;
        }

        ksort($scenesMap, SORT_NUMERIC);

        $updatedAt = $this->updated_at instanceof \DateTimeInterface
            ? Carbon::instance($this->updated_at)->toIso8601String()
            : (string) ($this->updated_at ?? now()->toIso8601String());

        return [
            'version' => (int) ($this->version ?? 2),
            'updated_at' => $updatedAt,
            'start_scene_id' => (int) ($this->start_scene_id ?? 495),
            'scenes' => $scenesMap,
        ];
    }
}
