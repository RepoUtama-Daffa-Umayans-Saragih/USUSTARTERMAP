<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\BuildingApiController;
use App\Models\Mongo\CampusRoutingGraph;
use App\Models\Mongo\FibVirtualWalkGraph;
use App\Models\Mongo\Photo360pScan;
use App\Models\Photo360Calibration;
use App\Observers\GitSnapshotObserver;
use App\Services\Cache\CampusRedisService;
use App\Services\Foto360p\Photo360pScanRepository;
use App\Services\GitSnapshotSyncService;
use App\Services\RdfGeneratorService;
use App\Services\Routing\CampusGraphBuilderService;
use App\Services\Routing\CampusRoutingService;
use App\Services\VirtualWalk\FibVirtualWalkGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use PDO;

class MsbdMigrateDataCommand extends Command
{
    protected $signature = 'msbd:migrate-data {--verify-only : Skip ETL writes and only verify 4-database data parity}';

    protected $description = 'Idempotent ETL cutover from SQLite & flat JSON into PostgreSQL+PostGIS, MongoDB, Redis, and Fuseki TDB2';

    /**
     * 19 domain tables ordered strictly Parent -> Child for safe FK insertion.
     *
     * @var array<int, string>
     */
    protected const DOMAIN_TABLES = [
        'users',
        'buildings',
        'rooms',
        'room_media',
        'lecturers',
        'courses',
        'schedules',
        'services',
        'fotocopy_stores',
        'photocopy_places',
        'fotocopy_services',
        'photocopy_items',
        'photocopy_place_service',
        'shop_reviews',
        'fotocopy_orders',
        'fotocopy_order_items',
        'fotocopy_order_files',
        'whatsapp_dispatch_logs',
        'campus_routing_overrides',
    ];

    protected const PG_DERIVED_COLUMNS = [
        'geom',
        'entrance_geom',
        'search_vector',
    ];

    protected const AUDITED_TABLES = [
        'schedules',
        'fotocopy_orders',
        'fotocopy_stores',
        'fotocopy_services',
    ];

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Default DB connection must be pgsql before running msbd:migrate-data (current: ' . DB::getDriverName() . ').');
            return self::FAILURE;
        }

        $sqlitePath = database_path('database.sqlite');
        if (!is_file($sqlitePath)) {
            $this->error("Source SQLite database not found: {$sqlitePath}");
            return self::FAILURE;
        }

        $fusekiUrl = rtrim((string) config('sparql.fuseki_url', ''), '/');
        $fusekiPort = parse_url($fusekiUrl, PHP_URL_PORT);
        if ((int) $fusekiPort === 3030) {
            $this->error("Unsafe FUSEKI_URL ({$fusekiUrl}): must target MSBD Fuseki (:3031), never host WS Fuseki (:3030).");
            return self::FAILURE;
        }

        $sqlite = $this->openSqliteReadOnly($sqlitePath);

        if ($this->option('verify-only')) {
            return $this->verifyAndRenderParity($sqlite);
        }

        GitSnapshotObserver::withoutSync(function () use ($sqlite): void {
            $this->info('Step 1/5: Migrating 19 domain tables + calibrations from SQLite/snapshots into PostgreSQL...');
            $this->migrateSqliteToPostgres($sqlite);

            $this->info('Step 2/5: Resetting PG sequences, refreshing Materialized Views & syncing Git state...');
            $this->postProcessPostgres();

            $this->info('Step 3/5: Ensuring MongoDB indexes & migrating FIB Walk, 360P Scans, and Campus Routing Graph...');
            $this->migrateDocumentsToMongo();

            $this->info('Step 4/5: Warming up Redis GEO sets and compiled application caches...');
            $this->warmupRedis();

            $this->info('Step 5/5: Generating & uploading RDF + 360 calibrations to Fuseki TDB2 (:3031)...');
            $this->syncFusekiMsbd();
        });

        return $this->verifyAndRenderParity($sqlite);
    }

    protected function openSqliteReadOnly(string $sqlitePath): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('PDO::SQLITE_OPEN_READONLY')) {
            $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
        }

        return new PDO('sqlite:' . $sqlitePath, null, null, $options);
    }

    protected function migrateSqliteToPostgres(PDO $sqlite): void
    {
        $pgColumnTypes = $this->loadPostgresColumnTypes();
        $sqlitePayload = [];
        foreach (self::DOMAIN_TABLES as $table) {
            $sqlitePayload[$table] = $sqlite->query("SELECT * FROM \"{$table}\" ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        }

        DB::transaction(function () use ($sqlitePayload, $pgColumnTypes): void {
            foreach (self::AUDITED_TABLES as $auditedTable) {
                if (Schema::hasTable($auditedTable)) {
                    DB::statement("ALTER TABLE {$auditedTable} DISABLE TRIGGER trg_audit_critical_mutations");
                }
            }
            if (Schema::hasTable('fotocopy_services')) {
                DB::statement('ALTER TABLE fotocopy_services DISABLE TRIGGER trg_recalc_store_starting_price');
            }

            // Break circular FK between users.store_id and fotocopy_stores.user_id before pruning.
            DB::table('users')->update(['store_id' => null]);

            if (Schema::hasTable('photo_360_calibrations')) {
                DB::table('photo_360_calibrations')->delete();
            }
            foreach (array_reverse(self::DOMAIN_TABLES) as $table) {
                DB::table($table)->delete();
            }

            $deferredUserStores = [];

            foreach (self::DOMAIN_TABLES as $table) {
                $rows = $sqlitePayload[$table];
                if (empty($rows)) {
                    continue;
                }

                $colTypes = $pgColumnTypes[$table] ?? [];
                $normalized = [];

                foreach ($rows as $row) {
                    $filtered = [];
                    foreach ($colTypes as $col => $pgType) {
                        if (in_array($col, self::PG_DERIVED_COLUMNS, true)) {
                            continue;
                        }
                        if (!array_key_exists($col, $row)) {
                            continue;
                        }

                        $val = $row[$col];

                        if ($table === 'users' && $col === 'store_id' && $val !== null && $val !== '') {
                            $deferredUserStores[(int) $row['id']] = (int) $val;
                            $val = null;
                        } elseif ($pgType === 'boolean') {
                            if ($val === '' || $val === null) {
                                $val = null;
                            } else {
                                $val = (bool) (int) $val;
                            }
                        } elseif (in_array($pgType, ['jsonb', 'json'], true)) {
                            if ($val === '' || $val === null) {
                                $val = null;
                            } elseif (is_array($val) || is_object($val)) {
                                $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }
                        } elseif ($val === '' && in_array($pgType, [
                            'integer', 'bigint', 'smallint', 'numeric', 'double precision', 'real', 'date',
                            'timestamp without time zone', 'timestamp with time zone',
                        ], true)) {
                            $val = null;
                        }

                        $filtered[$col] = $val;
                    }

                    if (!empty($filtered)) {
                        $normalized[] = $filtered;
                    }
                }

                foreach (array_chunk($normalized, 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }

                if ($table === 'fotocopy_stores' && !empty($deferredUserStores)) {
                    foreach ($deferredUserStores as $userId => $storeId) {
                        DB::table('users')->where('id', $userId)->update(['store_id' => $storeId]);
                    }
                }
            }

            $calibrations = $this->loadCalibrationsFromSnapshot();
            foreach ($calibrations as $item) {
                Photo360Calibration::updateOrCreate(
                    ['filename' => (string) $item['filename']],
                    [
                        'pitch' => (float) ($item['pitch'] ?? 0.0),
                        'yaw' => (float) ($item['yaw'] ?? 0.0),
                    ]
                );
            }

            foreach (self::AUDITED_TABLES as $auditedTable) {
                if (Schema::hasTable($auditedTable)) {
                    DB::statement("ALTER TABLE {$auditedTable} ENABLE TRIGGER trg_audit_critical_mutations");
                }
            }
            if (Schema::hasTable('fotocopy_services')) {
                DB::statement('ALTER TABLE fotocopy_services ENABLE TRIGGER trg_recalc_store_starting_price');
            }
        });
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function loadPostgresColumnTypes(): array
    {
        $map = [];
        $rows = DB::select("
            SELECT table_name, column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public'
            ORDER BY ordinal_position
        ");

        foreach ($rows as $row) {
            $map[$row->table_name][$row->column_name] = $row->data_type;
        }

        return $map;
    }

    protected function postProcessPostgres(): void
    {
        DB::select('SELECT * FROM fn_reset_all_pk_sequences()');
        DB::statement('REFRESH MATERIALIZED VIEW mv_fotocopy_daily_revenue_14d');
        DB::statement('REFRESH MATERIALIZED VIEW mv_catalog_search_index');

        $manifestPath = database_path('seeders/snapshots/manifest.json');
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $hash = is_array($manifest) ? (string) ($manifest['content_hash'] ?? '') : '';
            if ($hash !== '') {
                $statePath = storage_path('app/git_snapshot_state.json');
                if (!is_dir(dirname($statePath))) {
                    mkdir(dirname($statePath), 0755, true);
                }
                file_put_contents($statePath, json_encode([
                    'last_synced_hash' => $hash,
                    'last_synced_at' => now()->toIso8601String(),
                    'direction' => 'msbd_etl_cutover',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
            }
        }
    }

    protected function migrateDocumentsToMongo(): void
    {
        $this->call('msbd:mongodb-indexes');

        // 1. FIB Virtual Walk Graph (68 scenes)
        $fibPath = $this->resolveExistingFile([
            storage_path('app/virtual-walk/fib_walk_graph.json'),
            database_path('seeders/snapshots/fib_walk_graph.json'),
        ]);
        if ($fibPath !== null) {
            $fibRaw = json_decode((string) file_get_contents($fibPath), true);
            if (is_array($fibRaw) && !empty($fibRaw['scenes'])) {
                $version = (int) ($fibRaw['version'] ?? FibVirtualWalkGraphService::GRAPH_VERSION);
                $sha1 = sha1_file($fibPath) ?: null;
                FibVirtualWalkGraph::updateOrCreate(
                    ['version' => $version],
                    FibVirtualWalkGraph::fromRawGraph($fibRaw, $sha1)
                );
            }
        }

        // 2. Photo 360P Scans (249 scans)
        $scanPayload = $this->buildPhoto360pCatalog();
        $context = [
            'scan_engine' => $scanPayload['scan_engine'],
            'generated_at' => $scanPayload['generated_at'],
        ];
        $importedIds = [];
        foreach ($scanPayload['items'] as $item) {
            $photoId = (int) $item['id'];
            $importedIds[] = $photoId;
            Photo360pScan::updateOrCreate(
                ['photo_id' => $photoId],
                Photo360pScan::fromScanItem($item, $context)
            );
        }
        if (!empty($importedIds)) {
            Photo360pScan::whereNotIn('photo_id', $importedIds)->delete();
        }

        // 3. Campus Routing Graph (3,141 nodes / 3,378 edges)
        $routingPath = $this->resolveExistingFile([
            storage_path('app/routing/usu_campus_graph.json'),
            database_path('seeders/snapshots/campus_routing_graph.json'),
        ]);
        if ($routingPath !== null) {
            $routingRaw = json_decode((string) file_get_contents($routingPath), true);
            if (is_array($routingRaw) && !empty($routingRaw['nodes'])) {
                app(CampusGraphBuilderService::class)->persistToMongo($routingRaw);
            }
        }
    }

    protected function warmupRedis(): void
    {
        /** @var CampusRedisService $redis */
        $redis = app(CampusRedisService::class);
        $redis->syncBuildingsGeo();
        $redis->syncPlacesGeo();

        $redis->invalidateBuildingCaches();
        $redis->invalidateVirtualWalkCache();

        app(BuildingApiController::class)->index();
        app(FibVirtualWalkGraphService::class)->getGraph(false);

        /** @var CampusRoutingService $routingService */
        $routingService = app(CampusRoutingService::class);
        $routingService->invalidateCache();
        $routingService->getCompiledGraph();
    }

    protected function syncFusekiMsbd(): void
    {
        /** @var RdfGeneratorService $rdf */
        $rdf = app(RdfGeneratorService::class);
        $rdf->generate();
        if (!$rdf->uploadToFuseki(true)) {
            $this->warn('Warning: uploadToFuseki(true) reported failure.');
        }

        app(GitSnapshotSyncService::class)->syncPhotoCalibrationsToFuseki();
    }

    protected function verifyAndRenderParity(PDO $sqlite): int
    {
        $rows = [];
        $allPassed = true;

        $addRow = static function (string $component, int|string $expected, int|string $actual, bool $ok) use (&$rows, &$allPassed): void {
            if (!$ok) {
                $allPassed = false;
            }
            $rows[] = [$component, (string) $expected, (string) $actual, $ok ? 'MATCH' : 'MISMATCH'];
        };

        foreach (self::DOMAIN_TABLES as $table) {
            $srcCount = (int) $sqlite->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
            $dstCount = (int) DB::table($table)->count();
            $addRow("PG:{$table}", $srcCount, $dstCount, $srcCount === $dstCount);
        }

        $calExpected = count($this->loadCalibrationsFromSnapshot());
        $calActual = (int) Photo360Calibration::count();
        $addRow('PG:photo_360_calibrations', $calExpected, $calActual, $calExpected === $calActual);

        $nullGeomBuildings = (int) DB::table('buildings')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where(fn ($q) => $q->whereNull('geom')->orWhereNull('entrance_geom'))
            ->count();
        $addRow('PG:buildings_null_geom', 0, $nullGeomBuildings, $nullGeomBuildings === 0);

        $nullGeomPlaces = (int) DB::table('photocopy_places')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->where(fn ($q) => $q->whereNull('geom')->orWhereNull('entrance_geom'))
            ->count();
        $addRow('PG:places_null_geom', 0, $nullGeomPlaces, $nullGeomPlaces === 0);

        $nullGeomStores = (int) DB::table('fotocopy_stores')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNull('geom')
            ->count();
        $addRow('PG:stores_null_geom', 0, $nullGeomStores, $nullGeomStores === 0);

        $nullVecServices = (int) DB::table('fotocopy_services')->whereNull('search_vector')->count();
        $addRow('PG:services_null_tsv', 0, $nullVecServices, $nullVecServices === 0);

        $nullVecItems = (int) DB::table('photocopy_items')->whereNull('search_vector')->count();
        $addRow('PG:items_null_tsv', 0, $nullVecItems, $nullVecItems === 0);

        $mvCatCount = (int) DB::table('mv_catalog_search_index')->count();
        $itemsCount = (int) DB::table('photocopy_items')->count();
        $addRow('PG:mv_catalog_search_index', $itemsCount, $mvCatCount, $mvCatCount === $itemsCount);

        $mvRevCount = (int) DB::table('mv_fotocopy_daily_revenue_14d')->count();
        $addRow('PG:mv_fotocopy_daily_revenue_14d', '>= 1', $mvRevCount, $mvRevCount >= 1);

        // MongoDB parity
        $fibPath = $this->resolveExistingFile([
            storage_path('app/virtual-walk/fib_walk_graph.json'),
            database_path('seeders/snapshots/fib_walk_graph.json'),
        ]);
        $fibExpectedScenes = 68;
        if ($fibPath !== null) {
            $fibJson = json_decode((string) file_get_contents($fibPath), true);
            $fibExpectedScenes = count($fibJson['scenes'] ?? []);
        }
        $fibDoc = FibVirtualWalkGraph::orderByDesc('version')->first();
        $fibActualScenes = is_array($fibDoc?->scenes) ? count($fibDoc->scenes) : 0;
        $addRow('Mongo:fib_walk_graphs(scenes)', $fibExpectedScenes, $fibActualScenes, $fibActualScenes === $fibExpectedScenes);

        $expectedScans = count($this->buildPhoto360pCatalog()['items']);
        $actualScans = (int) Photo360pScan::count();
        $addRow('Mongo:photo_360p_scans', $expectedScans, $actualScans, $actualScans === $expectedScans);

        $routingPath = $this->resolveExistingFile([
            storage_path('app/routing/usu_campus_graph.json'),
            database_path('seeders/snapshots/campus_routing_graph.json'),
        ]);
        $expNodes = 3141;
        $expEdges = 3378;
        if ($routingPath !== null) {
            $rg = json_decode((string) file_get_contents($routingPath), true);
            $expNodes = count($rg['nodes'] ?? []);
            $expEdges = count($rg['edges'] ?? []);
        }
        $routingDoc = CampusRoutingGraph::where('is_active', true)->orderByDesc('generated_at')->first();
        $actNodes = is_array($routingDoc?->nodes) ? count($routingDoc->nodes) : 0;
        $actEdges = is_array($routingDoc?->edges) ? count($routingDoc->edges) : 0;
        $addRow('Mongo:campus_routing(nodes)', $expNodes, $actNodes, $actNodes === $expNodes);
        $addRow('Mongo:campus_routing(edges)', $expEdges, $actEdges, $actEdges === $expEdges);

        // Redis parity
        $redisConn = Redis::connection('default');
        $geoBuildings = (int) $redisConn->command('ZCARD', [CampusRedisService::KEY_GEO_BUILDINGS]);
        $geoPlaces = (int) $redisConn->command('ZCARD', [CampusRedisService::KEY_GEO_PLACES]);
        $addRow('Redis:usu:geo:buildings', (int) DB::table('buildings')->count(), $geoBuildings, $geoBuildings === (int) DB::table('buildings')->count());
        $addRow('Redis:usu:geo:places', (int) DB::table('photocopy_places')->where('is_active', true)->count(), $geoPlaces, $geoPlaces === (int) DB::table('photocopy_places')->where('is_active', true)->count());

        $warmKeys = [
            CampusRedisService::KEY_BUILDINGS_ALL_WIB,
            CampusRedisService::KEY_FIB_WALK_GRAPH,
            CampusRedisService::KEY_ROUTING_COMPILED_V2,
        ];
        foreach ($warmKeys as $wk) {
            $present = Cache::has($wk) ? 1 : 0;
            $addRow("Redis:{$wk}", 1, $present, $present === 1);
        }

        // Fuseki :3031 parity
        $fusekiTriples = $this->countFusekiTriples();
        $addRow('Fuseki(:3031):triples', '>= 5158', $fusekiTriples ?? 'ERR', $fusekiTriples !== null && $fusekiTriples >= 5158);

        $this->newLine();
        $this->table(['Komponen / Target', 'Sumber / Expected', 'Target Aktual', 'Status'], $rows);

        if (!$allPassed) {
            $this->error('Paritas data 4 database gagal: terdapat ketidaksesuaian angka.');
            return self::FAILURE;
        }

        $this->info('Paritas 4 database (PostgreSQL + MongoDB + Redis + Fuseki :3031) COCOK 100%.');
        return self::SUCCESS;
    }

    /**
     * @return array<int, array{filename: string, pitch: float, yaw: float}>
     */
    protected function loadCalibrationsFromSnapshot(): array
    {
        $path = database_path('seeders/snapshots/photo_360_calibrations.json');
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn ($row): bool => is_array($row) && !empty($row['filename'])
        ));
    }

    /**
     * Build deterministic 249-item 360P scan catalog from scan_results.json or disk markdown + image pool.
     *
     * @return array{generated_at: string, total_images: int, scan_engine: string, items: array<int, array<string, mixed>>}
     */
    protected function buildPhoto360pCatalog(): array
    {
        $scanPath = storage_path('app/foto-360p/scan_results.json');
        $renamePath = storage_path('app/foto-360p/rename_mapping.json');
        $renameMap = [];

        if (is_file($renamePath)) {
            $decodedRename = json_decode((string) file_get_contents($renamePath), true);
            $entries = is_array($decodedRename) ? ($decodedRename['items'] ?? $decodedRename) : [];
            foreach ($entries as $entry) {
                if (is_array($entry) && !empty($entry['id'])) {
                    $renameMap[(int) $entry['id']] = $entry;
                }
            }
        }

        if (is_file($scanPath)) {
            $decoded = json_decode((string) file_get_contents($scanPath), true);
            if (is_array($decoded) && !empty($decoded['items'])) {
                $items = [];
                foreach ($decoded['items'] as $item) {
                    if (!is_array($item) || empty($item['id'])) {
                        continue;
                    }
                    $id = (int) $item['id'];
                    if (isset($renameMap[$id]['original_filename']) && empty($item['original_filename'])) {
                        $item['original_filename'] = $renameMap[$id]['original_filename'];
                    }
                    $items[$id] = $item;
                }
                ksort($items);
                return [
                    'generated_at' => (string) ($decoded['generated_at'] ?? now()->toIso8601String()),
                    'total_images' => count($items),
                    'scan_engine' => (string) ($decoded['scan_engine'] ?? 'scan_results_json'),
                    'items' => array_values($items),
                ];
            }
        }

        $items = [];
        $parsedMd = $this->parseMarkdownDeepScan((string) config('foto360p.markdown_path'));
        $publicRoot = public_path(str_replace('/', DIRECTORY_SEPARATOR, trim((string) config('foto360p.public_dir', 'images/foto-360p'), '/')));

        $rootFiles = glob($publicRoot . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
        natsort($rootFiles);
        foreach ($rootFiles as $filePath) {
            $filename = basename($filePath);
            $id = (int) pathinfo($filename, PATHINFO_FILENAME);
            if ($id <= 0) {
                continue;
            }
            $md = $parsedMd[$id] ?? [];
            $info = @getimagesize($filePath);
            $w = (int) ($md['width'] ?? (is_array($info) ? $info[0] : 0));
            $h = (int) ($md['height'] ?? (is_array($info) ? $info[1] : 0));
            $sizeKb = round(((int) (@filesize($filePath) ?: 0)) / 1024, 1);

            $items[] = [
                'id' => $id,
                'filename' => $filename,
                'original_filename' => $md['original_filename'] ?? ($renameMap[$id]['original_filename'] ?? $filename),
                'image_url' => '/images/foto-360p/' . $filename,
                'width' => $w,
                'height' => $h,
                'size_kb' => $sizeKb,
                'projection' => Photo360pScanRepository::projectionFor($w, $h),
                'status' => $md['status'] ?? 'no_text',
                'detected_rooms' => $md['detected_rooms'] ?? [],
                'all_readable_texts' => array_values(array_map(
                    static fn (array $r): string => (string) ($r['raw_text'] ?? $r['room_name'] ?? ''),
                    $md['detected_rooms'] ?? []
                )),
                'scene_summary' => $md['scene_summary'] ?? '',
                'verified_by_admin' => false,
                'admin_note' => '',
            ];
        }

        $subFiles = [];
        if (is_dir($publicRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($publicRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relPath = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($publicRoot)), '/\\'));
                if (!str_contains($relPath, '/')) {
                    continue;
                }
                $subFiles[] = [$relPath, $file->getPathname()];
            }
        }

        usort($subFiles, static fn (array $a, array $b): int => strnatcasecmp($a[0], $b[0]));
        $nextId = empty($items) ? 1 : (max(array_column($items, 'id')) + 1);

        foreach ($subFiles as [$relPath, $absPath]) {
            $folder = explode('/', $relPath)[0] ?? 'campus';
            $buildingCode = strtoupper(str_replace('-', '_', $folder));
            $info = @getimagesize($absPath);
            $w = is_array($info) ? (int) $info[0] : 0;
            $h = is_array($info) ? (int) $info[1] : 0;
            $sizeKb = round(((int) (@filesize($absPath) ?: 0)) / 1024, 1);

            $items[] = [
                'id' => $nextId++,
                'filename' => $relPath,
                'original_filename' => basename($relPath),
                'building_code' => $buildingCode,
                'image_url' => '/images/foto-360p/' . $relPath,
                'width' => $w,
                'height' => $h,
                'size_kb' => $sizeKb,
                'projection' => Photo360pScanRepository::projectionFor($w, $h),
                'status' => 'indexed_faculty',
                'detected_rooms' => [],
                'all_readable_texts' => [],
                'scene_summary' => "Panorama fakultas pool {$folder}",
                'verified_by_admin' => false,
                'admin_note' => '',
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'total_images' => count($items),
            'scan_engine' => 'deep_scan_md_and_public_pool',
            'items' => $items,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parseMarkdownDeepScan(string $mdPath): array
    {
        if (!is_file($mdPath)) {
            return [];
        }

        $lines = file($mdPath, FILE_IGNORE_NEW_LINES) ?: [];
        $parsed = [];
        $inMainTable = false;

        foreach ($lines as $line) {
            if (str_contains($line, '## 3. Tabel Lengkap Hasil Scan')) {
                $inMainTable = true;
                continue;
            }
            if (!$inMainTable || !str_starts_with(trim($line), '|')) {
                continue;
            }

            $cols = array_map('trim', explode('|', trim($line, " \t\r\n|")));
            if (count($cols) < 7 || !ctype_digit($cols[0])) {
                continue;
            }

            $id = (int) $cols[0];
            $original = trim($cols[2], '` ');
            $dims = preg_split('/[×x]/u', $cols[3]) ?: [];
            $status = trim($cols[4], '` ');

            $rooms = [];
            if ($cols[5] !== '—' && $cols[5] !== '-') {
                foreach (explode('<br>', $cols[5]) as $part) {
                    if (preg_match('/\*\*(.+?)\*\*\s*`([^`]*)`\s*\(([-\d.]+)°,\s*([-\d.]+)°\)/u', $part, $m)) {
                        $rooms[] = [
                            'room_name' => trim($m[1]),
                            'raw_text' => trim($m[2]) !== '' ? trim($m[2]) : trim($m[1]),
                            'verified_read' => trim($m[2]) !== '' ? trim($m[2]) : trim($m[1]),
                            'category' => 'ruang_kuliah',
                            'confidence' => 'high',
                            'yaw' => (float) $m[3],
                            'pitch' => (float) $m[4],
                            'evidence_note' => 'Imported from DAFTAR_RUANGAN_DEEP_SCAN_360P.md',
                            'source' => 'ai',
                        ];
                    }
                }
            }

            $parsed[$id] = [
                'original_filename' => $original,
                'width' => isset($dims[0]) ? (int) trim($dims[0]) : 0,
                'height' => isset($dims[1]) ? (int) trim($dims[1]) : 0,
                'status' => $status,
                'detected_rooms' => $rooms,
                'scene_summary' => $cols[6],
            ];
        }

        return $parsed;
    }

    /**
     * @param  array<int, string>  $candidates
     */
    protected function resolveExistingFile(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function countFusekiTriples(): ?int
    {
        $endpoint = (string) config('sparql.query_endpoint');
        if ($endpoint === '') {
            return null;
        }

        try {
            $resp = Http::asForm()
                ->timeout(5)
                ->accept('application/sparql-results+json')
                ->post($endpoint, ['query' => 'SELECT (COUNT(*) AS ?c) WHERE { ?s ?p ?o }']);

            if (!$resp->successful()) {
                return null;
            }

            $val = $resp->json('results.bindings.0.c.value');
            return $val !== null ? (int) $val : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
