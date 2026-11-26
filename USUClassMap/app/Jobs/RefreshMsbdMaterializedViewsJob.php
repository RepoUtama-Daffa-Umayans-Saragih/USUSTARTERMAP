<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshMsbdMaterializedViewsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $target = 'all')
    {
    }

    public function handle(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $lock = null;
        try {
            $lock = Cache::lock("usu:lock:mv_refresh:{$this->target}", 30);
            if (!$lock->get()) {
                return;
            }
        } catch (\Throwable $e) {
            Log::debug('RefreshMsbdMaterializedViewsJob: proceeding without lock (' . $e->getMessage() . ')');
            $lock = null;
        }

        try {
            $views = match ($this->target) {
                'revenue' => ['mv_fotocopy_daily_revenue_14d'],
                'catalog' => ['mv_catalog_search_index'],
                default => ['mv_fotocopy_daily_revenue_14d', 'mv_catalog_search_index'],
            };

            foreach ($views as $mv) {
                $this->refreshView($mv);
            }
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    protected function refreshView(string $mvName): void
    {
        try {
            DB::connection('pgsql')->statement("REFRESH MATERIALIZED VIEW CONCURRENTLY {$mvName}");
        } catch (\Throwable $e) {
            Log::warning("RefreshMsbdMaterializedViewsJob: concurrent refresh fallback for {$mvName}: " . $e->getMessage());
            DB::connection('pgsql')->statement("REFRESH MATERIALIZED VIEW {$mvName}");
        }
    }
}
