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

        DB::statement("
            CREATE OR REPLACE VIEW v_schedule_complete AS
            SELECT
                s.id AS schedule_id,
                s.day,
                s.time_start,
                s.time_end,
                s.semester,
                s.class_group,
                c.id AS course_id,
                c.code AS course_code,
                c.name AS course_name,
                c.credits,
                c.type AS course_type,
                l.id AS lecturer_id,
                l.name AS lecturer_name,
                l.nip AS lecturer_nip,
                l.email AS lecturer_email,
                r.id AS room_id,
                r.name AS room_name,
                r.floor AS room_floor,
                r.capacity AS room_capacity,
                r.photo_360_path,
                r.door_photo_path,
                b.id AS building_id,
                b.name AS building_name,
                b.code AS building_code,
                b.faculty,
                b.latitude,
                b.longitude
            FROM schedules s
            JOIN courses c ON c.id = s.course_id
            JOIN lecturers l ON l.id = s.lecturer_id
            JOIN rooms r ON r.id = s.room_id
            JOIN buildings b ON b.id = r.building_id
        ");

        DB::statement("
            CREATE OR REPLACE VIEW v_building_room_media_summary AS
            SELECT
                b.id AS building_id,
                b.name,
                b.code,
                b.faculty,
                b.category,
                b.latitude,
                b.longitude,
                b.floor_count,
                COUNT(DISTINCT r.id)::INTEGER AS rooms_count,
                COUNT(DISTINCT rm.id)::INTEGER AS media_count,
                COUNT(DISTINCT rm.id) FILTER (WHERE rm.type = '360')::INTEGER AS media_360_count,
                COUNT(DISTINCT rm.id) FILTER (WHERE rm.type = 'photo')::INTEGER AS media_photo_count,
                COUNT(DISTINCT r.id) FILTER (WHERE r.door_photo_path IS NOT NULL AND r.door_photo_path <> '')::INTEGER AS door_photos_count
            FROM buildings b
            LEFT JOIN rooms r ON r.building_id = b.id
            LEFT JOIN room_media rm ON rm.room_id = r.id
            GROUP BY b.id, b.name, b.code, b.faculty, b.category, b.latitude, b.longitude, b.floor_count
        ");

        DB::statement("
            CREATE OR REPLACE VIEW v_photocopy_directory_active AS
            SELECT
                pp.id AS photocopy_place_id,
                fs.id AS fotocopy_store_id,
                pp.name,
                pp.slug,
                pp.location_name,
                pp.latitude,
                pp.longitude,
                pp.entrance_lat,
                pp.entrance_lng,
                pp.open_time,
                pp.close_time,
                pp.operating_days,
                pp.whatsapp,
                pp.image_url,
                pp.rating,
                pp.user_rating_count,
                pp.is_active,
                COALESCE(fs.starting_price, item_stats.min_item_price, 200)::INTEGER AS starting_price,
                COALESCE(item_stats.available_items_count, 0)::INTEGER AS available_items_count,
                COALESCE(item_stats.atk_goods_count, 0)::INTEGER AS atk_goods_count,
                fn_is_store_open_wib(pp.open_time, pp.close_time, pp.operating_days) AS is_open_wib
            FROM photocopy_places pp
            LEFT JOIN fotocopy_stores fs ON fs.slug = pp.slug
            LEFT JOIN (
                SELECT
                    photocopy_place_id,
                    COUNT(*) FILTER (WHERE is_available = TRUE) AS available_items_count,
                    COUNT(*) FILTER (WHERE is_available = TRUE AND item_type = 'barang') AS atk_goods_count,
                    MIN(price) FILTER (WHERE is_available = TRUE) AS min_item_price
                FROM photocopy_items
                GROUP BY photocopy_place_id
            ) item_stats ON item_stats.photocopy_place_id = pp.id
            WHERE pp.is_active = TRUE
        ");

        DB::statement("
            CREATE OR REPLACE VIEW v_cashier_order_queue AS
            SELECT
                o.id AS order_id,
                o.order_code,
                o.invoice_number,
                o.pickup_code,
                o.store_id,
                s.name AS store_name,
                o.customer_name,
                o.customer_phone,
                o.customer_whatsapp,
                o.payment_method,
                o.payment_status,
                o.order_status,
                o.wa_dispatch_status,
                o.total_price,
                o.paid_at,
                o.created_at,
                COALESCE(file_stats.files_count, 0)::INTEGER AS files_count,
                COALESCE(file_stats.total_pages, 0)::INTEGER AS total_pages,
                COALESCE(item_stats.items_count, 0)::INTEGER AS items_count
            FROM fotocopy_orders o
            JOIN fotocopy_stores s ON s.id = o.store_id
            LEFT JOIN (
                SELECT
                    fotocopy_order_id,
                    COUNT(*) AS files_count,
                    COALESCE(SUM(page_count * copies), 0) AS total_pages
                FROM fotocopy_order_files
                WHERE deleted_at IS NULL
                GROUP BY fotocopy_order_id
            ) file_stats ON file_stats.fotocopy_order_id = o.id
            LEFT JOIN (
                SELECT
                    order_id,
                    COUNT(*) AS items_count
                FROM fotocopy_order_items
                GROUP BY order_id
            ) item_stats ON item_stats.order_id = o.id
        ");

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_fotocopy_daily_revenue_14d CASCADE');
        DB::statement("
            CREATE MATERIALIZED VIEW mv_fotocopy_daily_revenue_14d AS
            SELECT
                o.store_id,
                o.created_at::date AS order_date_wib,
                COALESCE(o.payment_method, 'QRIS') AS payment_method,
                COALESCE(o.order_status, 'Menunggu') AS order_status,
                COALESCE(o.payment_status, 'pending') AS payment_status,
                COUNT(*)::BIGINT AS orders_count,
                COUNT(*) FILTER (WHERE o.payment_status = 'paid')::BIGINT AS paid_count,
                COALESCE(SUM(o.total_price), 0)::BIGINT AS total_revenue,
                COALESCE(SUM(o.total_price) FILTER (WHERE o.payment_status = 'paid'), 0)::BIGINT AS paid_revenue
            FROM fotocopy_orders o
            GROUP BY
                o.store_id,
                o.created_at::date,
                COALESCE(o.payment_method, 'QRIS'),
                COALESCE(o.order_status, 'Menunggu'),
                COALESCE(o.payment_status, 'pending')
            WITH DATA
        ");
        DB::statement('
            CREATE UNIQUE INDEX ux_mv_rev_14d
            ON mv_fotocopy_daily_revenue_14d (store_id, order_date_wib, payment_method, order_status, payment_status)
        ');

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_catalog_search_index CASCADE');
        DB::statement("
            CREATE MATERIALIZED VIEW mv_catalog_search_index AS
            SELECT
                pi.id AS item_id,
                pp.id AS photocopy_place_id,
                fs.id AS fotocopy_store_id,
                pp.name AS place_name,
                pp.slug AS place_slug,
                lower(regexp_replace(pp.slug, '[^a-zA-Z0-9]', '', 'g')) AS compact_slug,
                lower(unaccent(pi.name)) AS normalized_name,
                pi.name,
                pi.category,
                pi.item_type,
                pi.sub_category,
                pi.brand,
                pi.pack_qty,
                pi.price,
                pi.unit,
                pi.stock,
                pi.image_path,
                pi.metadata,
                pi.is_available,
                pp.is_active AS place_active,
                pp.open_time,
                pp.close_time,
                pp.operating_days,
                pp.rating,
                COALESCE(fs.starting_price, 200)::INTEGER AS starting_price,
                COALESCE(pp.entrance_geom, pp.geom) AS geom,
                pi.search_vector
            FROM photocopy_items pi
            JOIN photocopy_places pp ON pp.id = pi.photocopy_place_id
            LEFT JOIN fotocopy_stores fs ON fs.slug = pp.slug
            WITH DATA
        ");
        DB::statement('CREATE UNIQUE INDEX ux_mv_cat_item_id ON mv_catalog_search_index (item_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_catalog_search_index CASCADE');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_fotocopy_daily_revenue_14d CASCADE');
        DB::statement('DROP VIEW IF EXISTS v_cashier_order_queue CASCADE');
        DB::statement('DROP VIEW IF EXISTS v_photocopy_directory_active CASCADE');
        DB::statement('DROP VIEW IF EXISTS v_building_room_media_summary CASCADE');
        DB::statement('DROP VIEW IF EXISTS v_schedule_complete CASCADE');
    }
};
