<?php

namespace App\Models\Mongo;

use MongoDB\Laravel\Eloquent\Model;

class WhatsappDispatchEvent extends Model
{
    public const TTL_DAYS = 180;

    protected $connection = 'mongodb';
    protected $table = 'whatsapp_dispatch_events';

    protected $fillable = [
        'order_id',
        'order_code',
        'driver',
        'recipient',
        'status',
        'payload',
        'raw_response',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
