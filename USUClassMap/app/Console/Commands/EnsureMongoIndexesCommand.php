<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Connection as MongoConnection;

class EnsureMongoIndexesCommand extends Command
{
    protected $signature = 'msbd:mongodb-indexes {--database= : Target MongoDB database name override}';

    protected $description = 'Ensure idempotent MongoDB indexes (unique, compound, text, 2dsphere, TTL) across all 6 MSBD collections';

    public function handle(): int
    {
        /** @var MongoConnection $conn */
        $conn = DB::connection('mongodb');
        $dbOption = $this->option('database');
        $db = is_string($dbOption) && trim($dbOption) !== ''
            ? $conn->getDatabase(trim($dbOption))
            : $conn->getDatabase();

        $specs = self::indexDefinitions();

        foreach ($specs as $collectionName => $indexes) {
            $collection = $db->selectCollection($collectionName);

            foreach ($indexes as $def) {
                $collection->createIndex($def['keys'], $def['options']);
            }

            $names = [];
            foreach ($collection->listIndexes() as $idx) {
                $names[] = $idx->getName();
            }

            $this->info(sprintf(
                '[%s.%s] indexes (%d): %s',
                $db->getDatabaseName(),
                $collectionName,
                count($names),
                implode(', ', $names)
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<int, array{keys: array<string, mixed>, options: array<string, mixed>}>>
     */
    public static function indexDefinitions(): array
    {
        return [
            'fib_walk_graphs' => [
                [
                    'keys' => ['version' => 1],
                    'options' => ['unique' => true, 'name' => 'ux_fib_walk_version'],
                ],
                [
                    'keys' => ['scenes.location' => '2dsphere'],
                    'options' => ['sparse' => true, 'name' => 'idx_fib_scenes_location_2dsphere'],
                ],
            ],
            'photo_360p_scans' => [
                [
                    'keys' => ['photo_id' => 1],
                    'options' => ['unique' => true, 'name' => 'ux_photo_360p_photo_id'],
                ],
                [
                    'keys' => ['verified' => 1, 'building_code' => 1],
                    'options' => ['name' => 'idx_photo_360p_verified_building'],
                ],
                [
                    'keys' => ['ocr_text' => 'text', 'detected_rooms' => 'text'],
                    'options' => ['name' => 'idx_photo_360p_text', 'default_language' => 'none'],
                ],
            ],
            'campus_routing_graphs' => [
                [
                    'keys' => ['is_active' => 1, 'generated_at' => -1],
                    'options' => ['name' => 'idx_routing_active_generated'],
                ],
            ],
            'ai_copilot_sessions' => [
                [
                    'keys' => ['session_token' => 1, 'updated_at' => -1],
                    'options' => ['name' => 'idx_copilot_session_updated'],
                ],
                [
                    'keys' => ['user_id' => 1],
                    'options' => ['name' => 'idx_copilot_user_id'],
                ],
                [
                    'keys' => ['expires_at' => 1],
                    'options' => ['expireAfterSeconds' => 0, 'name' => 'ttl_copilot_expires_at'],
                ],
            ],
            'whatsapp_dispatch_events' => [
                [
                    'keys' => ['order_code' => 1, 'created_at' => -1],
                    'options' => ['name' => 'idx_wa_events_order_created'],
                ],
                [
                    'keys' => ['driver' => 1, 'status' => 1],
                    'options' => ['name' => 'idx_wa_events_driver_status'],
                ],
                [
                    'keys' => ['expires_at' => 1],
                    'options' => ['expireAfterSeconds' => 0, 'name' => 'ttl_wa_events_expires_at'],
                ],
            ],
            'search_analytics_logs' => [
                [
                    'keys' => ['endpoint' => 1, 'detected_mode' => 1, 'created_at' => -1],
                    'options' => ['name' => 'idx_search_logs_endpoint_mode_created'],
                ],
                [
                    'keys' => ['expires_at' => 1],
                    'options' => ['expireAfterSeconds' => 0, 'name' => 'ttl_search_logs_expires_at'],
                ],
            ],
        ];
    }
}
