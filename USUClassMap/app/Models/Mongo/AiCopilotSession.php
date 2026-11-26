<?php

namespace App\Models\Mongo;

use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Eloquent\Model;

class AiCopilotSession extends Model
{
    public const TTL_DAYS = 30;

    protected $connection = 'mongodb';
    protected $table = 'ai_copilot_sessions';

    protected $fillable = [
        'session_token',
        'user_id',
        'turns',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Atomically append a conversation turn to MongoDB without blocking the caller on failure.
     *
     * @param  array<string, mixed>  $turn
     */
    public static function recordTurn(string $sessionToken, ?int $userId, array $turn): void
    {
        $now = new UTCDateTime(now());
        $expiresAt = new UTCDateTime(now()->addDays(self::TTL_DAYS));

        $collection = DB::connection('mongodb')->getCollection('ai_copilot_sessions');
        $collection->updateOne(
            ['session_token' => $sessionToken],
            [
                '$push' => ['turns' => $turn],
                '$set' => [
                    'user_id' => $userId,
                    'expires_at' => $expiresAt,
                    'updated_at' => $now,
                ],
                '$setOnInsert' => [
                    'session_token' => $sessionToken,
                    'created_at' => $now,
                ],
            ],
            ['upsert' => true]
        );
    }
}
