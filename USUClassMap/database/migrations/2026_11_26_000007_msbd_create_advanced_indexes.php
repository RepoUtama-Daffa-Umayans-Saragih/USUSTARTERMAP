<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // 1. Spatial GiST & btree_gist indexes
        DB::statement('CREATE INDEX IF NOT EXISTS idx_buildings_geom_gist ON buildings USING GIST (geom)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_buildings_entrance_gist ON buildings USING GIST (entrance_geom)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_places_geom_gist ON photocopy_places USING GIST (geom)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_places_entrance_gist ON photocopy_places USING GIST (entrance_geom)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_stores_geom_gist ON fotocopy_stores USING GIST (geom)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_mv_cat_geom_gist ON mv_catalog_search_index USING GIST (geom)');
        DB::statement('
            CREATE INDEX IF NOT EXISTS idx_schedules_room_day_sem_range_gist
            ON schedules USING GIST (
                room_id,
                day,
                semester,
                int4range(
                    (EXTRACT(HOUR FROM time_start)::int * 60 + EXTRACT(MINUTE FROM time_start)::int),
                    (EXTRACT(HOUR FROM time_end)::int * 60 + EXTRACT(MINUTE FROM time_end)::int)
                )
            )
        ');

        // 2. Full-Text GIN (tsvector)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_fc_services_fts_gin ON fotocopy_services USING GIN (search_vector)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pc_items_fts_gin ON photocopy_items USING GIN (search_vector)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_mv_cat_fts_gin ON mv_catalog_search_index USING GIN (search_vector)');
        DB::statement("
            CREATE INDEX IF NOT EXISTS idx_places_fts_gin
            ON photocopy_places USING GIN (
                to_tsvector('indonesian', coalesce(name, '') || ' ' || coalesce(location_name, '') || ' ' || coalesce(description, ''))
            )
        ");

        // 3. Trigram GIN (pg_trgm)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pc_items_name_trgm ON photocopy_items USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_fc_services_name_trgm ON fotocopy_services USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_places_name_trgm ON photocopy_places USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_courses_name_trgm ON courses USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_lecturers_name_trgm ON lecturers USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_mv_cat_name_trgm ON mv_catalog_search_index USING GIN (name gin_trgm_ops)');

        // 4. JSONB GIN
        DB::statement('CREATE INDEX IF NOT EXISTS idx_fc_services_metadata_gin ON fotocopy_services USING GIN (metadata jsonb_path_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_pc_items_metadata_gin ON photocopy_items USING GIN (metadata jsonb_path_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_payment_payload_gin ON fotocopy_orders USING GIN (payment_payload)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_overrides_coords_gin ON campus_routing_overrides USING GIN (coordinates_json)');

        // 5. Partial Indexes
        DB::statement('CREATE INDEX IF NOT EXISTS idx_places_active_partial ON photocopy_places (rating DESC) WHERE is_active = TRUE');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_items_available_partial ON photocopy_items (photocopy_place_id, price) WHERE is_available = TRUE');
        DB::statement("CREATE INDEX IF NOT EXISTS idx_orders_queue_active_partial ON fotocopy_orders (store_id, created_at DESC) WHERE order_status IN ('Menunggu', 'Diproses')");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_wa_logs_failed_partial ON whatsapp_dispatch_logs (created_at DESC) WHERE status IN ('queued', 'failed')");

        // 6. Composite B-Tree
        DB::statement('CREATE INDEX IF NOT EXISTS idx_schedules_room_day_time ON schedules (room_id, day, time_start)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_store_pay_created ON fotocopy_orders (store_id, payment_status, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_order_files_order_sort ON fotocopy_order_files (fotocopy_order_id, sort_order) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $indexes = [
            'idx_buildings_geom_gist',
            'idx_buildings_entrance_gist',
            'idx_places_geom_gist',
            'idx_places_entrance_gist',
            'idx_stores_geom_gist',
            'idx_mv_cat_geom_gist',
            'idx_schedules_room_day_sem_range_gist',
            'idx_fc_services_fts_gin',
            'idx_pc_items_fts_gin',
            'idx_mv_cat_fts_gin',
            'idx_places_fts_gin',
            'idx_pc_items_name_trgm',
            'idx_fc_services_name_trgm',
            'idx_places_name_trgm',
            'idx_courses_name_trgm',
            'idx_lecturers_name_trgm',
            'idx_mv_cat_name_trgm',
            'idx_fc_services_metadata_gin',
            'idx_pc_items_metadata_gin',
            'idx_orders_payment_payload_gin',
            'idx_overrides_coords_gin',
            'idx_places_active_partial',
            'idx_items_available_partial',
            'idx_orders_queue_active_partial',
            'idx_wa_logs_failed_partial',
            'idx_schedules_room_day_time',
            'idx_orders_store_pay_created',
            'idx_order_files_order_sort',
        ];

        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
