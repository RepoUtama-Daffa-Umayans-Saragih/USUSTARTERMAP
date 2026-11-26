<?php

namespace Tests\Feature;

use App\Jobs\DispatchOrderToWhatsAppJob;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\FotocopyOrder;
use App\Models\FotocopyOrderItem;
use App\Models\FotocopyService;
use App\Models\FotocopyStore;
use App\Models\Mongo\AiCopilotSession;
use App\Models\Mongo\FibVirtualWalkGraph;
use App\Models\Mongo\SearchAnalyticsLog;
use App\Models\Mongo\WhatsappDispatchEvent;
use App\Models\OrderStatusHistory;
use App\Models\PhotocopyItem;
use App\Models\PhotocopyPlace;
use App\Models\WhatsappDispatchLog;
use App\Observers\GitSnapshotObserver;
use App\Services\Cache\CampusRedisService;
use App\Services\Payment\PaymentManager;
use App\Services\VirtualWalk\FibVirtualWalkGraphService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

class MsbdArchitectureTest extends TestCase
{
    protected static bool $schemaPrepared = false;

    /** @var array{host: string, port: string, db: string, user: string, pass: string, mongoUri: string, mongoDb: string, redisHost: string, redisPort: string}|null */
    protected static ?array $connParams = null;

    protected bool $previousGitSyncMuted = false;

    /** @var array<int, string> */
    protected array $generatedLocalFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousGitSyncMuted = GitSnapshotObserver::$isMuted;
        GitSnapshotObserver::$isMuted = true;

        $pgHost = (string) config('database.connections.pgsql.host', '127.0.0.1');
        $pgPort = (string) config('database.connections.pgsql.port', '5434');
        $pgUser = (string) config('database.connections.pgsql.username', 'usuclassmap');
        $pgPass = (string) config('database.connections.pgsql.password', 'usuclassmap_secret');
        $pgDb = 'usuclassmap_test';
        $mongoUri = (string) config('database.connections.mongodb.dsn', 'mongodb://127.0.0.1:27017');
        $mongoDb = 'usuclassmap_doc_test';
        $redisHost = (string) config('database.redis.default.host', '127.0.0.1');
        $redisPort = (string) config('database.redis.default.port', '6379');

        self::$connParams = [
            'host' => $pgHost,
            'port' => $pgPort,
            'db' => $pgDb,
            'user' => $pgUser,
            'pass' => $pgPass,
            'mongoUri' => $mongoUri,
            'mongoDb' => $mongoDb,
            'redisHost' => $redisHost,
            'redisPort' => $redisPort,
        ];

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => $pgHost,
            'database.connections.pgsql.port' => $pgPort,
            'database.connections.pgsql.database' => $pgDb,
            'database.connections.pgsql.username' => $pgUser,
            'database.connections.pgsql.password' => $pgPass,
            'database.connections.mongodb.database' => $mongoDb,
            'database.redis.default.database' => '5',
            'database.redis.cache.database' => '6',
            'cache.default' => 'redis',
            'queue.default' => 'sync',
            'sparql.auto_sync' => false,
            'whatsapp.driver' => 'sandbox',
            'whatsapp.sync_dispatch' => true,
        ]);

        DB::purge('pgsql');
        DB::purge('mongodb');
        Redis::purge('default');
        Redis::purge('cache');
        Cache::forgetDriver('redis');

        try {
            DB::connection('pgsql')->getPdo();
            DB::connection('mongodb')->getMongoClient()->listDatabases();
            Redis::connection('default')->command('PING');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Multi-DB services required for MsbdArchitectureTest are unavailable: ' . $e->getMessage());
        }

        $this->assertSame($pgDb, DB::connection('pgsql')->getDatabaseName());
        $this->assertSame($mongoDb, DB::connection('mongodb')->getMongoDB()->getDatabaseName());

        if (!self::$schemaPrepared) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            Artisan::call('msbd:mongodb-indexes', ['--database' => $mongoDb]);
            self::$schemaPrepared = true;
        }

        $this->resetIsolatedTestState();
    }

    protected function tearDown(): void
    {
        foreach ($this->generatedLocalFiles as $relPath) {
            if ($relPath !== '' && Storage::disk('local')->exists($relPath)) {
                Storage::disk('local')->delete($relPath);
            }
        }
        $this->generatedLocalFiles = [];

        try {
            $this->resetIsolatedTestState();
        } catch (\Throwable) {
        }

        GitSnapshotObserver::$isMuted = $this->previousGitSyncMuted;

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$connParams !== null) {
            $p = self::$connParams;
            try {
                $pdo = new PDO("pgsql:host={$p['host']};port={$p['port']};dbname={$p['db']}", $p['user'], $p['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE');
                $pdo->exec('CREATE SCHEMA public');
            } catch (\Throwable) {
            }

            try {
                $client = new \MongoDB\Client($p['mongoUri']);
                $client->dropDatabase($p['mongoDb']);
            } catch (\Throwable) {
            }

            try {
                foreach ([5, 6] as $dbIndex) {
                    $predis = new \Predis\Client([
                        'scheme' => 'tcp',
                        'host' => $p['redisHost'],
                        'port' => (int) $p['redisPort'],
                        'database' => $dbIndex,
                    ]);
                    $predis->flushdb();
                }
            } catch (\Throwable) {
            }
        }

        self::$schemaPrepared = false;
        self::$connParams = null;

        parent::tearDownAfterClass();
    }

    protected function resetIsolatedTestState(): void
    {
        DB::connection('pgsql')->statement('
            TRUNCATE TABLE
                order_status_histories,
                audit_logs,
                whatsapp_dispatch_logs,
                fotocopy_order_files,
                fotocopy_order_items,
                fotocopy_orders,
                photocopy_items,
                fotocopy_services,
                photocopy_place_service,
                shop_reviews,
                photocopy_places,
                fotocopy_stores,
                schedules,
                courses,
                lecturers,
                room_media,
                rooms,
                photo_360_calibrations,
                buildings,
                services,
                campus_routing_overrides,
                users
            RESTART IDENTITY CASCADE
        ');

        DB::connection('mongodb')->getMongoClient()->dropDatabase('usuclassmap_doc_test');
        Artisan::call('msbd:mongodb-indexes', ['--database' => 'usuclassmap_doc_test']);

        Redis::connection('default')->command('FLUSHDB');
        Redis::connection('cache')->command('FLUSHDB');
    }

    public function test_postgres_triggers_spatial_starting_price_fts_and_partitioned_audit_tables(): void
    {
        $building = Building::create([
            'name' => 'Gedung Uji Arsitektur MSBD',
            'code' => 'MSBD-BLDG',
            'faculty' => 'FASILKOM-TI',
            'latitude' => 3.5625,
            'longitude' => 98.6565,
            'entrance_lat' => 3.5628,
            'entrance_lng' => 98.6569,
            'floor_count' => 3,
        ]);

        $geomRow = DB::selectOne("
            SELECT
                geom IS NOT NULL AS has_geom,
                entrance_geom IS NOT NULL AS has_entrance,
                ROUND(ST_Y(geom::geometry)::numeric, 4) AS geom_lat,
                ROUND(ST_X(entrance_geom::geometry)::numeric, 4) AS ent_lng
            FROM buildings
            WHERE id = ?
        ", [$building->id]);

        $this->assertTrue((bool) $geomRow->has_geom);
        $this->assertTrue((bool) $geomRow->has_entrance);
        $this->assertSame('3.5625', (string) $geomRow->geom_lat);
        $this->assertSame('98.6569', (string) $geomRow->ent_lng);

        $building->update(['latitude' => 3.5631, 'longitude' => 98.6572]);
        $updatedLat = DB::selectOne('SELECT ROUND(ST_Y(geom::geometry)::numeric, 4) AS lat FROM buildings WHERE id = ?', [$building->id]);
        $this->assertSame('3.5631', (string) $updatedLat->lat);

        $store = FotocopyStore::create([
            'name' => 'Toko Trigger Test',
            'slug' => 'toko-trigger-test',
            'location_name' => 'Depan Fasilkom',
            'latitude' => 3.5624,
            'longitude' => 98.6564,
            'starting_price' => 9999,
            'is_open' => true,
        ]);

        $service = FotocopyService::create([
            'store_id' => $store->id,
            'name' => 'Print Laser Warna A4',
            'category' => 'Print',
            'item_type' => 'jasa',
            'price' => 750,
            'unit' => 'lembar',
            'stock' => 20,
            'is_available' => true,
        ]);

        $this->assertSame(750, (int) $store->fresh()->starting_price);

        $tsvCheck = DB::selectOne('SELECT search_vector IS NOT NULL AS has_vec FROM fotocopy_services WHERE id = ?', [$service->id]);
        $this->assertTrue((bool) $tsvCheck->has_vec);

        $service->update(['price' => 350]);
        $this->assertSame(350, (int) $store->fresh()->starting_price);

        $order = FotocopyOrder::create([
            'order_code' => 'ORD-TEST-AUDIT-01',
            'store_id' => $store->id,
            'customer_name' => 'Tester Audit',
            'customer_phone' => '081234567890',
            'customer_whatsapp' => '6281234567890',
            'payment_method' => 'qris',
            'payment_channel' => 'QRIS',
            'payment_status' => 'pending',
            'order_status' => 'Menunggu',
            'wa_dispatch_status' => 'not_ready',
            'total_price' => 700,
        ]);

        $order->update(['order_status' => 'Diproses']);

        $auditActions = AuditLog::where('table_name', 'fotocopy_orders')
            ->where('record_id', $order->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();
        $this->assertSame(['INSERT', 'UPDATE'], $auditActions);

        $histories = OrderStatusHistory::where('order_id', $order->id)
            ->orderBy('id')
            ->get(['old_order_status', 'new_order_status']);
        $this->assertCount(2, $histories);
        $this->assertNull($histories[0]->old_order_status);
        $this->assertSame('Menunggu', $histories[0]->new_order_status);
        $this->assertSame('Menunggu', $histories[1]->old_order_status);
        $this->assertSame('Diproses', $histories[1]->new_order_status);
    }

    public function test_stored_procedures_sequences_stock_check_and_payment_lock_idempotency(): void
    {
        $code1 = DB::selectOne('SELECT fn_generate_order_code() AS v')->v;
        $code2 = DB::selectOne('SELECT fn_generate_order_code() AS v')->v;
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-\d{4,}$/', $code1);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-\d{4,}$/', $code2);
        $this->assertNotSame($code1, $code2);

        $inv1 = DB::selectOne('SELECT fn_generate_invoice_number() AS v')->v;
        $inv2 = DB::selectOne('SELECT fn_generate_invoice_number() AS v')->v;
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{4,}$/', $inv1);
        $this->assertNotSame($inv1, $inv2);

        $store = FotocopyStore::create([
            'name' => 'Toko Proc Test',
            'slug' => 'toko-proc-test',
            'location_name' => 'Koridor FIB',
            'latitude' => 3.5621,
            'longitude' => 98.6561,
            'whatsapp' => '6285769737410',
            'is_open' => true,
        ]);

        $service = FotocopyService::create([
            'store_id' => $store->id,
            'name' => 'Pulpen Gel Hitam 0.5',
            'category' => 'ATK',
            'item_type' => 'barang',
            'price' => 5000,
            'unit' => 'pcs',
            'stock' => 5,
            'is_available' => true,
        ]);

        $procOrder = DB::selectOne(
            'CALL sp_create_fotocopy_order_atomic(?, NULL, ?, ?, ?, ?::jsonb, NULL, NULL)',
            [
                $store->id,
                'Mahasiswa Uji',
                '6281234567890',
                'qris',
                json_encode([['service_id' => $service->id, 'quantity' => 2]]),
            ]
        );

        $this->assertNotNull($procOrder->p_order_id);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-\d{4,}$/', (string) $procOrder->p_order_code);
        $this->assertSame(3, (int) $service->fresh()->stock);

        $overstockRejected = false;
        try {
            DB::statement(
                'CALL sp_create_fotocopy_order_atomic(?, NULL, ?, ?, ?, ?::jsonb, NULL, NULL)',
                [
                    $store->id,
                    'Mahasiswa Overstock',
                    '6281234567890',
                    'qris',
                    json_encode([['service_id' => $service->id, 'quantity' => 10]]),
                ]
            );
        } catch (QueryException) {
            $overstockRejected = true;
        }
        $this->assertTrue($overstockRejected);
        $this->assertSame(3, (int) $service->fresh()->stock);

        $negativeCheckRejected = false;
        try {
            DB::table('fotocopy_services')->where('id', $service->id)->update(['stock' => -1]);
        } catch (QueryException) {
            $negativeCheckRejected = true;
        }
        $this->assertTrue($negativeCheckRejected);

        $order = FotocopyOrder::findOrFail((int) $procOrder->p_order_id);
        $order->update(['payment_channel' => 'QRIS']);

        $paymentManager = app(PaymentManager::class);
        $this->assertTrue($paymentManager->simulateSuccess($order));

        $paid1 = $order->fresh();
        if (!empty($paid1->invoice_pdf_path)) {
            $this->generatedLocalFiles[] = $paid1->invoice_pdf_path;
        }

        $this->assertTrue($paid1->isPaid());
        $this->assertSame('Diproses', $paid1->order_status);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{4,}$/', (string) $paid1->invoice_number);

        $waLogsAfter1 = WhatsappDispatchLog::where('fotocopy_order_id', $paid1->id)->count();
        $waEventsAfter1 = WhatsappDispatchEvent::where('order_code', $paid1->order_code)->count();
        $this->assertGreaterThanOrEqual(1, $waLogsAfter1);
        $this->assertSame($waLogsAfter1, $waEventsAfter1);

        $this->assertTrue($paymentManager->simulateSuccess($paid1));
        $paid2 = $order->fresh();

        $this->assertSame($paid1->invoice_number, $paid2->invoice_number);
        $this->assertSame($waLogsAfter1, WhatsappDispatchLog::where('fotocopy_order_id', $paid1->id)->count());
        $this->assertSame($waEventsAfter1, WhatsappDispatchEvent::where('order_code', $paid1->order_code)->count());

        $directProc = DB::selectOne(
            'CALL sp_mark_order_paid_atomic(?, ?, ?::jsonb, NULL, NULL)',
            [$paid1->order_code, 'QRIS', json_encode(['probe' => true])]
        );
        $this->assertTrue((bool) $directProc->p_was_already_paid);
        $this->assertSame($paid1->invoice_number, $directProc->p_invoice_number);
    }

    public function test_postgis_nearby_functions_pg_trgm_and_materialized_views_concurrent_refresh(): void
    {
        Building::create([
            'name' => 'Fakultas Ilmu Budaya',
            'code' => 'FIB',
            'faculty' => 'FIB',
            'latitude' => 3.5625,
            'longitude' => 98.6565,
            'floor_count' => 3,
        ]);

        $store = FotocopyStore::create([
            'name' => 'Fotocopy Dekat FIB',
            'slug' => 'fotocopy-dekat-fib',
            'location_name' => 'Jl. Universitas',
            'latitude' => 3.5627,
            'longitude' => 98.6566,
            'starting_price' => 300,
            'is_open' => true,
        ]);

        $place = PhotocopyPlace::create([
            'name' => 'Fotocopy Dekat FIB',
            'slug' => 'fotocopy-dekat-fib',
            'location_name' => 'Jl. Universitas',
            'latitude' => 3.5627,
            'longitude' => 98.6566,
            'open_time' => '08:00',
            'close_time' => '21:00',
            'operating_days' => 'Senin - Minggu',
            'rating' => 4.9,
            'is_active' => true,
        ]);

        PhotocopyItem::create([
            'photocopy_place_id' => $place->id,
            'name' => 'Print Warna Inkjet A4',
            'category' => 'Print',
            'item_type' => 'jasa',
            'sub_category' => 'Print Warna',
            'price' => 1000,
            'unit' => 'lembar',
            'stock' => 100,
            'is_available' => true,
        ]);

        $order = FotocopyOrder::create([
            'order_code' => 'ORD-MV-TEST-001',
            'store_id' => $store->id,
            'customer_name' => 'Pelanggan MV',
            'payment_method' => 'qris',
            'payment_status' => 'paid',
            'order_status' => 'Selesai',
            'total_price' => 25000,
            'paid_at' => now(),
        ]);
        FotocopyOrderItem::create([
            'order_id' => $order->id,
            'service_name' => 'Print Warna',
            'category' => 'Print',
            'price' => 1000,
            'quantity' => 25,
            'subtotal' => 25000,
        ]);

        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_catalog_search_index');
        DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_fotocopy_daily_revenue_14d');

        $nearbyPlaces = DB::select('SELECT * FROM fn_find_nearby_photocopy_places(?, ?, ?, ?)', [3.5625, 98.6565, 1000, 10]);
        $this->assertNotEmpty($nearbyPlaces);
        $this->assertSame($place->id, (int) $nearbyPlaces[0]->id);
        $this->assertLessThan(100, (int) $nearbyPlaces[0]->distance_m);

        $nearbyBuildings = DB::select('SELECT * FROM fn_find_nearby_buildings(?, ?, ?, ?)', [3.5625, 98.6565, 1000, 10]);
        $this->assertNotEmpty($nearbyBuildings);
        $this->assertSame('FIB', $nearbyBuildings[0]->code);

        $mvCatRows = DB::table('mv_catalog_search_index')
            ->where(function ($q): void {
                $q->whereRaw('name % ?', ['Print Warna Inkjet'])
                    ->orWhereRaw("search_vector @@ plainto_tsquery('indonesian', unaccent(?))", ['print warna']);
            })
            ->get();
        $this->assertCount(1, $mvCatRows);
        $this->assertSame('Print Warna Inkjet A4', $mvCatRows[0]->name);

        $mvRev = DB::table('mv_fotocopy_daily_revenue_14d')
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->first();
        $this->assertNotNull($mvRev);
        $this->assertSame(1, (int) $mvRev->orders_count);
        $this->assertSame(25000, (int) $mvRev->paid_revenue);
    }

    public function test_mongodb_odm_indexes_and_redis_cache_invalidation(): void
    {
        $rawGraph = [
            'version' => 2,
            'start_scene_id' => 495,
            'updated_at' => now()->toIso8601String(),
            'scenes' => [
                495 => [
                    'id' => 495,
                    'filename' => '495.jpg',
                    'label' => 'Gerbang Awal FIB',
                    'zone' => 'Gerbang',
                    'floor' => 1,
                    'type' => 'corridor',
                    'lat' => 3.5625,
                    'lng' => 98.6565,
                    'yaw' => 0.0,
                    'pitch' => 0.0,
                    'hotspots' => [],
                ],
            ],
        ];

        $walkService = app(FibVirtualWalkGraphService::class);
        $walkService->persistToMongo($rawGraph, null);
        app(CampusRedisService::class)->invalidateVirtualWalkCache();

        $graph1 = $walkService->getGraph(false);
        $this->assertSame('Gerbang Awal FIB', $graph1['scenes']['495']['label'] ?? null);
        $this->assertTrue(Cache::has(CampusRedisService::KEY_FIB_WALK_GRAPH));

        $rawGraph['scenes'][495]['label'] = 'Gerbang FIB Terupdate';
        $walkService->persistToMongo($rawGraph, null);
        app(CampusRedisService::class)->invalidateVirtualWalkCache();
        $this->assertFalse(Cache::has(CampusRedisService::KEY_FIB_WALK_GRAPH));

        $graph2 = $walkService->getGraph(false);
        $this->assertSame('Gerbang FIB Terupdate', $graph2['scenes']['495']['label'] ?? null);

        AiCopilotSession::recordTurn('arch-test-sess', 1, [
            'prompt' => 'cari pulpen',
            'reply' => 'ditemukan 3 toko',
            'intent' => 'smart_fotocopy_search',
            'tool_calls' => [['tool' => 'cari_fotokopi_atk']],
            'latency_ms' => 15,
            'provider' => 'smart_photocopy_engine',
        ]);
        $copilot = AiCopilotSession::where('session_token', 'arch-test-sess')->first();
        $this->assertNotNull($copilot);
        $this->assertCount(1, $copilot->turns ?? []);

        WhatsappDispatchEvent::create([
            'order_id' => 101,
            'order_code' => 'ORD-ARCH-WA-1',
            'driver' => 'sandbox',
            'recipient' => '6285769737410',
            'status' => 'sent',
            'payload' => ['kind' => 'summary'],
            'raw_response' => ['success' => true],
            'expires_at' => now()->addDays(WhatsappDispatchEvent::TTL_DAYS),
        ]);
        $this->assertSame(1, WhatsappDispatchEvent::where('order_code', 'ORD-ARCH-WA-1')->count());

        SearchAnalyticsLog::create([
            'endpoint' => '/api/fotocopy/smart-search',
            'query' => 'pulpen',
            'detected_mode' => 'product',
            'has_gps' => true,
            'result_count' => 4,
            'latency_ms' => 8,
            'meta' => ['used_mv' => true],
            'expires_at' => now()->addDays(SearchAnalyticsLog::TTL_DAYS),
        ]);
        $this->assertSame(1, SearchAnalyticsLog::where('endpoint', '/api/fotocopy/smart-search')->count());

        $mongoDb = DB::connection('mongodb')->getDatabase('usuclassmap_doc_test');
        $indexNames = static function (string $col) use ($mongoDb): array {
            $out = [];
            foreach ($mongoDb->selectCollection($col)->listIndexes() as $idx) {
                $out[] = $idx->getName();
            }
            return $out;
        };

        $this->assertContains('idx_fib_scenes_location_2dsphere', $indexNames('fib_walk_graphs'));
        $this->assertContains('ttl_copilot_expires_at', $indexNames('ai_copilot_sessions'));
        $this->assertContains('ttl_wa_events_expires_at', $indexNames('whatsapp_dispatch_events'));
        $this->assertContains('ttl_search_logs_expires_at', $indexNames('search_analytics_logs'));
    }
}
