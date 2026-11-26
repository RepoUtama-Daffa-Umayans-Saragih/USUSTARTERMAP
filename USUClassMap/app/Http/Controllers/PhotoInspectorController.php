<?php

namespace App\Http\Controllers;

use App\Models\Photo360Calibration;
use App\Services\GitSnapshotSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PhotoInspectorController extends Controller
{
    /**
     * Endpoint SPARQL Fuseki
     */
    protected string $queryEndpoint;
    protected string $updateEndpoint;

    public function __construct()
    {
        $this->queryEndpoint  = env('FUSEKI_QUERY_URL', 'http://localhost:3030/usuclassmap/query');
        $this->updateEndpoint = env('FUSEKI_UPDATE_URL', 'http://localhost:3030/usuclassmap/update');
    }

    /**
     * Display the 360 Photo Inspector utility page.
     * Merges PostgreSQL photo_360_calibrations and Apache Jena Fuseki Pitch & Yaw data.
     */
    public function index()
    {
        return $this->renderInspectorView('foto360', 'features.admin.cek-foto');
    }

    /**
     * Display the 360 Photo Inspector (Cylinder mode) utility page.
     */
    public function indexCylinder()
    {
        return $this->renderInspectorView('foto360p', 'features.admin.cek-foto-p');
    }

    protected function renderInspectorView(string $directory, string $viewName)
    {
        $disk = Storage::disk('public');
        $calibrations = $this->loadCalibrationsMap();

        $files = collect($disk->files($directory))
            ->filter(fn ($path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg']))
            ->map(function (string $path) use ($disk, $calibrations): array {
                $bytes    = $disk->exists($path) ? $disk->size($path) : 0;
                $modified = $disk->exists($path) ? $disk->lastModified($path) : time();
                $filename = basename($path);

                $savedPitch = $calibrations[$filename]['pitch'] ?? 0.0;
                $savedYaw   = $calibrations[$filename]['yaw']   ?? 0.0;

                return [
                    'filename'      => $filename,
                    'url'           => asset('storage/' . $path),
                    'size'          => $this->humanFilesize($bytes),
                    'size_bytes'    => $bytes,
                    'last_modified' => date('d M Y, H:i', $modified),
                    'path'          => $path,
                    'pitch'         => $savedPitch,
                    'yaw'           => $savedYaw,
                ];
            })
            ->sortBy('filename')
            ->values();

        return view($viewName, [
            'photos' => $files,
        ]);
    }

    /**
     * Load calibration map from Fuseki + PostgreSQL photo_360_calibrations (PG takes priority).
     *
     * @return array<string, array{pitch: float, yaw: float}>
     */
    protected function loadCalibrationsMap(): array
    {
        $calibrations = [];

        try {
            $sparqlSelect = <<<'SPARQL'
PREFIX ucm: <http://example.org/usuclassmap/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

SELECT ?filename ?pitch ?yaw
WHERE {
    ?photo ucm:hasFileName ?filename .
    OPTIONAL { ?photo ucm:hasPitch ?pitch . }
    OPTIONAL { ?photo ucm:hasYaw ?yaw . }
}
SPARQL;

            $response = Http::timeout(3)
                ->accept('application/sparql-results+json')
                ->get($this->queryEndpoint, [
                    'query' => $sparqlSelect,
                ]);

            if ($response->successful()) {
                $bindings = $response->json('results.bindings') ?? [];
                foreach ($bindings as $b) {
                    $fn = $b['filename']['value'] ?? null;
                    if ($fn) {
                        $calibrations[$fn] = [
                            'pitch' => isset($b['pitch']['value']) ? (float) $b['pitch']['value'] : 0.0,
                            'yaw'   => isset($b['yaw']['value'])   ? (float) $b['yaw']['value']   : 0.0,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('Apache Jena Fuseki SPARQL Query tidak dapat dihubungi: ' . $e->getMessage());
        }

        try {
            if (Schema::hasTable('photo_360_calibrations')) {
                foreach (Photo360Calibration::all(['filename', 'pitch', 'yaw']) as $row) {
                    $calibrations[(string) $row->filename] = [
                        'pitch' => (float) $row->pitch,
                        'yaw'   => (float) $row->yaw,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PhotoInspectorController PG calibrations read fallback: ' . $e->getMessage());
        }

        return $calibrations;
    }

    /**
     * Simpan koordinat Pitch & Yaw ke PostgreSQL, Git Snapshot, dan Apache Jena Fuseki via SPARQL Update.
     */
    public function saveCalibration(Request $request)
    {
        $validated = $request->validate([
            'filename' => 'required|string',
            'pitch'    => 'required|numeric',
            'yaw'      => 'required|numeric',
            'hfov'     => 'nullable|numeric|min:30|max:140',
        ]);

        $filename = $validated['filename'];
        $pitch    = (float) $validated['pitch'];
        $yaw      = (float) $validated['yaw'];
        $hfov     = isset($validated['hfov']) ? (float) $validated['hfov'] : 100.0;

        try {
            if (Schema::hasTable('photo_360_calibrations')) {
                Photo360Calibration::updateOrCreate(
                    ['filename' => $filename],
                    [
                        'pitch' => $pitch,
                        'yaw' => $yaw,
                        'hfov' => $hfov,
                        'updated_by' => auth()->id(),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('PhotoInspectorController PG saveCalibration skipped: ' . $e->getMessage());
        }

        try {
            app(GitSnapshotSyncService::class)->savePhotoCalibration($filename, $pitch, $yaw);
        } catch (\Throwable $snapshotException) {
            logger()->warning('Gagal menyimpan kalibrasi foto 360 ke snapshot Git: ' . $snapshotException->getMessage());
        }

        // Sanitasi ID untuk URI resource RDF (contoh: 424.jpg -> Photo_424)
        $cleanId  = preg_replace('/[^a-zA-Z0-9_]/', '_', pathinfo($filename, PATHINFO_FILENAME));
        $photoUri = "ucm:Photo_{$cleanId}";

        $sparqlUpdate = <<<SPARQL
PREFIX ucm: <http://example.org/usuclassmap/>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>

DELETE {
    {$photoUri} ucm:hasFileName ?oldFilename ;
                ucm:hasPitch ?oldPitch ;
                ucm:hasYaw ?oldYaw .
}
INSERT {
    {$photoUri} ucm:hasFileName "{$filename}" ;
                ucm:hasPitch "{$pitch}"^^xsd:float ;
                ucm:hasYaw "{$yaw}"^^xsd:float .
}
WHERE {
    OPTIONAL { {$photoUri} ucm:hasFileName ?oldFilename . }
    OPTIONAL { {$photoUri} ucm:hasPitch ?oldPitch . }
    OPTIONAL { {$photoUri} ucm:hasYaw ?oldYaw . }
}
SPARQL;

        if (config('sparql.auto_sync', true)) {
            try {
                $username = config('sparql.username', env('FUSEKI_USERNAME', 'admin'));
                $password = config('sparql.password', env('FUSEKI_PASSWORD', 'admin'));

                $httpRequest = Http::asForm()->timeout(5);
                if (!empty($username) && !empty($password)) {
                    $httpRequest = $httpRequest->withBasicAuth($username, $password);
                }

                $response = $httpRequest->post($this->updateEndpoint, [
                    'update' => $sparqlUpdate,
                ]);

                if (!$response->successful()) {
                    Log::warning('PhotoInspectorController Fuseki update failed (' . $response->status() . '): ' . $response->body());
                }
            } catch (\Throwable $e) {
                Log::warning('PhotoInspectorController Fuseki unreachable: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Koordinat Pitch & Yaw untuk {$filename} berhasil disimpan.",
            'data'    => [
                'filename'  => $filename,
                'pitch'     => $pitch,
                'yaw'       => $yaw,
                'photo_uri' => "http://example.org/usuclassmap/Photo_{$cleanId}",
            ],
        ]);
    }

    /**
     * Format raw bytes into human-readable size string (B, KB, MB, GB).
     */
    private function humanFilesize(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2) . ' GB';
        }

        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2) . ' MB';
        }

        if ($bytes >= 1_024) {
            return number_format($bytes / 1_024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}