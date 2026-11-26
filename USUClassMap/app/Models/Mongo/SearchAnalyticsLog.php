<?php

namespace App\Models\Mongo;

use MongoDB\Laravel\Eloquent\Model;

class SearchAnalyticsLog extends Model
{
    public const TTL_DAYS = 90;

    protected $connection = 'mongodb';
    protected $table = 'search_analytics_logs';

    protected $fillable = [
        'endpoint',
        'query',
        'detected_mode',
        'has_gps',
        'result_count',
        'latency_ms',
        'meta',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'has_gps' => 'boolean',
            'result_count' => 'integer',
            'latency_ms' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
