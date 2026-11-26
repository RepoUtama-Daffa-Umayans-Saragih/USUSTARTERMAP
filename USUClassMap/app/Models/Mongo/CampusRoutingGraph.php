<?php

namespace App\Models\Mongo;

use Illuminate\Support\Carbon;
use MongoDB\Laravel\Eloquent\Model;

class CampusRoutingGraph extends Model
{
    protected $connection = 'mongodb';
    protected $table = 'campus_routing_graphs';

    protected $fillable = [
        'version',
        'is_active',
        'generated_at',
        'meta',
        'nodes',
        'edges',
        'buildings',
        'barriers',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Prepare BSON-safe payload from compiled routing graph JSON array.
     *
     * @param  array<string, mixed>  $graphData
     * @return array<string, mixed>
     */
    public static function fromGraphArray(array $graphData, bool $isActive = true): array
    {
        $nodesList = [];
        foreach ($graphData['nodes'] ?? [] as $key => $node) {
            if (!is_array($node)) {
                continue;
            }
            $node['id'] = (string) ($node['id'] ?? $key);
            $nodesList[] = $node;
        }

        $obstacles = is_array($graphData['obstacles'] ?? null) ? $graphData['obstacles'] : [];

        $generatedAt = $graphData['generated_at'] ?? null;
        if (is_string($generatedAt) && $generatedAt !== '') {
            try {
                $generatedAt = Carbon::parse($generatedAt);
            } catch (\Throwable) {
                $generatedAt = now();
            }
        } elseif (!$generatedAt instanceof \DateTimeInterface) {
            $generatedAt = now();
        }

        return [
            'version' => (int) ($graphData['version'] ?? 1),
            'is_active' => $isActive,
            'generated_at' => $generatedAt,
            'meta' => [
                'bbox' => $graphData['bbox'] ?? null,
                'stats' => $graphData['stats'] ?? [],
                'generated_at_iso' => $generatedAt instanceof \DateTimeInterface
                    ? Carbon::instance($generatedAt)->toIso8601String()
                    : now()->toIso8601String(),
            ],
            'nodes' => $nodesList,
            'edges' => array_values($graphData['edges'] ?? []),
            'buildings' => array_values($obstacles['buildings'] ?? []),
            'barriers' => array_values($obstacles['barriers'] ?? []),
        ];
    }

    /**
     * Reconstruct compiled graph JSON structure expected by CampusRoutingService.
     *
     * @return array<string, mixed>
     */
    public function toGraphArray(): array
    {
        $nodesMap = [];
        foreach ($this->nodes ?? [] as $key => $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = (string) ($node['id'] ?? $key);
            $nodesMap[$id] = $node;
        }

        $meta = is_array($this->meta ?? null) ? $this->meta : [];
        $generatedIso = $meta['generated_at_iso']
            ?? ($this->generated_at instanceof \DateTimeInterface
                ? Carbon::instance($this->generated_at)->toIso8601String()
                : now()->toIso8601String());

        $payload = [
            'version' => (int) ($this->version ?? 1),
            'generated_at' => $generatedIso,
            'stats' => is_array($meta['stats'] ?? null) ? $meta['stats'] : [],
            'nodes' => $nodesMap,
            'edges' => is_array($this->edges ?? null) ? $this->edges : [],
            'obstacles' => [
                'buildings' => is_array($this->buildings ?? null) ? $this->buildings : [],
                'barriers' => is_array($this->barriers ?? null) ? $this->barriers : [],
            ],
        ];

        if (!empty($meta['bbox'])) {
            $payload['bbox'] = $meta['bbox'];
        }

        return $payload;
    }
}
