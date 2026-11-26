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

        // Spatial geography columns
        DB::statement('ALTER TABLE buildings ADD COLUMN IF NOT EXISTS geom geography(Point, 4326)');
        DB::statement('ALTER TABLE buildings ADD COLUMN IF NOT EXISTS entrance_geom geography(Point, 4326)');
        DB::statement('ALTER TABLE photocopy_places ADD COLUMN IF NOT EXISTS geom geography(Point, 4326)');
        DB::statement('ALTER TABLE photocopy_places ADD COLUMN IF NOT EXISTS entrance_geom geography(Point, 4326)');
        DB::statement('ALTER TABLE fotocopy_stores ADD COLUMN IF NOT EXISTS geom geography(Point, 4326)');

        // Backfill existing spatial coordinates if any rows exist
        DB::statement("
            UPDATE buildings
            SET geom = CASE
                    WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
                    ELSE NULL
                END,
                entrance_geom = CASE
                    WHEN entrance_lat IS NOT NULL AND entrance_lng IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(entrance_lng::double precision, entrance_lat::double precision), 4326)::geography
                    WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
                    ELSE NULL
                END
        ");

        DB::statement("
            UPDATE photocopy_places
            SET geom = CASE
                    WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
                    ELSE NULL
                END,
                entrance_geom = CASE
                    WHEN entrance_lat IS NOT NULL AND entrance_lng IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(entrance_lng::double precision, entrance_lat::double precision), 4326)::geography
                    WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
                    ELSE NULL
                END
        ");

        DB::statement("
            UPDATE fotocopy_stores
            SET geom = CASE
                    WHEN latitude IS NOT NULL AND longitude IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
                    ELSE NULL
                END
        ");

        // Full-text tsvector columns on catalog tables
        DB::statement('ALTER TABLE fotocopy_services ADD COLUMN IF NOT EXISTS search_vector tsvector');
        DB::statement('ALTER TABLE photocopy_items ADD COLUMN IF NOT EXISTS search_vector tsvector');

        $vectorExpr = "
            setweight(to_tsvector('indonesian', unaccent(coalesce(name, ''))), 'A') ||
            setweight(to_tsvector('indonesian', unaccent(coalesce(brand, '') || ' ' || coalesce(sub_category, ''))), 'B') ||
            setweight(to_tsvector('indonesian', unaccent(coalesce(category, '') || ' ' || coalesce(description, ''))), 'C')
        ";
        DB::statement("UPDATE fotocopy_services SET search_vector = {$vectorExpr}");
        DB::statement("UPDATE photocopy_items SET search_vector = {$vectorExpr}");

        // Convert JSON columns to JSONB for indexing and containment queries
        $nullableJsonColumns = [
            ['buildings', 'photo_360_meta'],
            ['rooms', 'photo_360_meta'],
            ['room_media', 'metadata'],
            ['photocopy_places', 'opening_hours'],
            ['photocopy_items', 'metadata'],
            ['fotocopy_services', 'metadata'],
            ['fotocopy_orders', 'payment_payload'],
            ['whatsapp_dispatch_logs', 'payload_json'],
            ['campus_routing_overrides', 'metadata_json'],
        ];

        foreach ($nullableJsonColumns as [$table, $column]) {
            DB::statement("
                ALTER TABLE {$table}
                ALTER COLUMN {$column} TYPE jsonb
                USING CASE
                    WHEN {$column} IS NULL OR btrim({$column}::text) = '' THEN NULL
                    ELSE {$column}::jsonb
                END
            ");
        }

        DB::statement("
            ALTER TABLE campus_routing_overrides
            ALTER COLUMN coordinates_json TYPE jsonb
            USING coordinates_json::jsonb
        ");

        // Domain & integrity CHECK constraints
        $constraints = [
            ['buildings', 'chk_buildings_coords', 'CHECK (latitude BETWEEN -11 AND 6 AND longitude BETWEEN 95 AND 141)'],
            ['buildings', 'chk_buildings_entrance_coords', 'CHECK ((entrance_lat IS NULL OR entrance_lat BETWEEN -11 AND 6) AND (entrance_lng IS NULL OR entrance_lng BETWEEN 95 AND 141))'],
            ['photocopy_places', 'chk_photocopy_places_coords', 'CHECK ((latitude IS NULL OR latitude BETWEEN -11 AND 6) AND (longitude IS NULL OR longitude BETWEEN 95 AND 141))'],
            ['photocopy_places', 'chk_photocopy_places_entrance_coords', 'CHECK ((entrance_lat IS NULL OR entrance_lat BETWEEN -11 AND 6) AND (entrance_lng IS NULL OR entrance_lng BETWEEN 95 AND 141))'],
            ['fotocopy_stores', 'chk_fotocopy_stores_coords', 'CHECK ((latitude IS NULL OR latitude BETWEEN -11 AND 6) AND (longitude IS NULL OR longitude BETWEEN 95 AND 141))'],
            ['fotocopy_stores', 'chk_fotocopy_stores_starting_price', 'CHECK (starting_price >= 0)'],
            ['schedules', 'chk_schedules_time_order', 'CHECK (time_end > time_start)'],
            ['fotocopy_services', 'chk_fotocopy_services_price_stock', 'CHECK (price >= 0 AND (stock IS NULL OR stock >= 0))'],
            ['photocopy_items', 'chk_photocopy_items_price_stock', 'CHECK (price >= 0 AND (stock IS NULL OR stock >= 0))'],
            ['fotocopy_orders', 'chk_fotocopy_orders_total_price', 'CHECK (total_price >= 0)'],
            ['fotocopy_orders', 'chk_fotocopy_orders_payment_status', "CHECK (payment_status IN ('pending', 'paid', 'unpaid', 'failed', 'refunded'))"],
            ['fotocopy_orders', 'chk_fotocopy_orders_order_status', "CHECK (order_status IN ('Menunggu', 'Diproses', 'Selesai', 'Dibatalkan', 'pending'))"],
            ['users', 'chk_users_role', "CHECK (role IN ('superadmin', 'admin_usuclassmap', 'admin', 'admin_fotokopi', 'cashier_fotokopi', 'user', 'mahasiswa'))"],
        ];

        foreach ($constraints as [$table, $name, $definition]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $constraints = [
            ['buildings', 'chk_buildings_coords'],
            ['buildings', 'chk_buildings_entrance_coords'],
            ['photocopy_places', 'chk_photocopy_places_coords'],
            ['photocopy_places', 'chk_photocopy_places_entrance_coords'],
            ['fotocopy_stores', 'chk_fotocopy_stores_coords'],
            ['fotocopy_stores', 'chk_fotocopy_stores_starting_price'],
            ['schedules', 'chk_schedules_time_order'],
            ['fotocopy_services', 'chk_fotocopy_services_price_stock'],
            ['photocopy_items', 'chk_photocopy_items_price_stock'],
            ['fotocopy_orders', 'chk_fotocopy_orders_total_price'],
            ['fotocopy_orders', 'chk_fotocopy_orders_payment_status'],
            ['fotocopy_orders', 'chk_fotocopy_orders_order_status'],
            ['users', 'chk_users_role'],
        ];

        foreach ($constraints as [$table, $name]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }

        $jsonColumns = [
            ['buildings', 'photo_360_meta'],
            ['rooms', 'photo_360_meta'],
            ['room_media', 'metadata'],
            ['photocopy_places', 'opening_hours'],
            ['photocopy_items', 'metadata'],
            ['fotocopy_services', 'metadata'],
            ['fotocopy_orders', 'payment_payload'],
            ['whatsapp_dispatch_logs', 'payload_json'],
            ['campus_routing_overrides', 'coordinates_json'],
            ['campus_routing_overrides', 'metadata_json'],
        ];

        foreach ($jsonColumns as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE json USING {$column}::json");
        }

        DB::statement('ALTER TABLE photocopy_items DROP COLUMN IF EXISTS search_vector');
        DB::statement('ALTER TABLE fotocopy_services DROP COLUMN IF EXISTS search_vector');
        DB::statement('ALTER TABLE fotocopy_stores DROP COLUMN IF EXISTS geom');
        DB::statement('ALTER TABLE photocopy_places DROP COLUMN IF EXISTS entrance_geom');
        DB::statement('ALTER TABLE photocopy_places DROP COLUMN IF EXISTS geom');
        DB::statement('ALTER TABLE buildings DROP COLUMN IF EXISTS entrance_geom');
        DB::statement('ALTER TABLE buildings DROP COLUMN IF EXISTS geom');
    }
};
