<?php

namespace App\Jobs;

use App\Services\GitSnapshotSyncService;
use App\Services\RdfGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RegenerateRdfJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('rdf');
    }

    /**
     * Execute the job to regenerate RDF graph and push to Jena Fuseki.
     */
    public function handle(RdfGeneratorService $rdfService): void
    {
        if (!config('sparql.auto_sync', true)) {
            Log::debug('RegenerateRdfJob: Skipped because sparql.auto_sync is disabled.');
            return;
        }

        Log::info('RegenerateRdfJob: Starting RDF synchronization triggered by database change.');

        $rdfService->generate();
        $uploaded = $rdfService->uploadToFuseki();

        if ($uploaded) {
            try {
                app(GitSnapshotSyncService::class)->syncPhotoCalibrationsToFuseki();
            } catch (\Throwable $e) {
                Log::warning('RegenerateRdfJob: Gagal menerapkan ulang kalibrasi foto 360 ke Fuseki: ' . $e->getMessage());
            }
        }

        Log::info('RegenerateRdfJob: RDF synchronization complete.');
    }
}
