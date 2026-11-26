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

        DB::statement('CREATE SEQUENCE IF NOT EXISTS seq_fotocopy_order_daily START WITH 1 INCREMENT BY 1');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS seq_fotocopy_invoice_daily START WITH 1 INCREMENT BY 1');

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_generate_order_code(
                p_date DATE DEFAULT (NOW() AT TIME ZONE 'Asia/Jakarta')::date
            )
            RETURNS VARCHAR(32)
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_seq BIGINT;
            BEGIN
                v_seq := nextval('seq_fotocopy_order_daily');
                RETURN 'ORD-' || to_char(COALESCE(p_date, (NOW() AT TIME ZONE 'Asia/Jakarta')::date), 'YYYYMMDD') || '-' || lpad(v_seq::text, 4, '0');
            END;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_generate_invoice_number(
                p_date DATE DEFAULT (NOW() AT TIME ZONE 'Asia/Jakarta')::date
            )
            RETURNS VARCHAR(40)
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_seq BIGINT;
            BEGIN
                v_seq := nextval('seq_fotocopy_invoice_daily');
                RETURN 'INV-' || to_char(COALESCE(p_date, (NOW() AT TIME ZONE 'Asia/Jakarta')::date), 'YYYYMMDD') || '-' || lpad(v_seq::text, 4, '0');
            END;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_find_nearby_photocopy_places(
                p_lat DOUBLE PRECISION,
                p_lng DOUBLE PRECISION,
                p_radius_m INTEGER DEFAULT 3000,
                p_limit INTEGER DEFAULT 50
            )
            RETURNS TABLE(
                id BIGINT,
                name VARCHAR,
                slug VARCHAR,
                distance_m INTEGER,
                is_active BOOLEAN,
                open_time VARCHAR,
                close_time VARCHAR
            )
            LANGUAGE sql
            STABLE
            AS \$\$
                SELECT
                    pp.id,
                    pp.name,
                    pp.slug,
                    ROUND(
                        ST_Distance(
                            COALESCE(pp.entrance_geom, pp.geom),
                            ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography
                        )
                    )::INTEGER AS distance_m,
                    pp.is_active,
                    pp.open_time,
                    pp.close_time
                FROM photocopy_places pp
                WHERE pp.is_active = TRUE
                  AND COALESCE(pp.entrance_geom, pp.geom) IS NOT NULL
                  AND ST_DWithin(
                        COALESCE(pp.entrance_geom, pp.geom),
                        ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography,
                        p_radius_m
                  )
                ORDER BY distance_m ASC, pp.rating DESC
                LIMIT p_limit;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_find_nearby_buildings(
                p_lat DOUBLE PRECISION,
                p_lng DOUBLE PRECISION,
                p_radius_m INTEGER DEFAULT 1500,
                p_limit INTEGER DEFAULT 10
            )
            RETURNS TABLE(
                id BIGINT,
                code VARCHAR,
                name VARCHAR,
                faculty VARCHAR,
                distance_m INTEGER
            )
            LANGUAGE sql
            STABLE
            AS \$\$
                SELECT
                    b.id,
                    b.code,
                    b.name,
                    b.faculty,
                    ROUND(
                        ST_Distance(
                            COALESCE(b.entrance_geom, b.geom),
                            ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography
                        )
                    )::INTEGER AS distance_m
                FROM buildings b
                WHERE COALESCE(b.entrance_geom, b.geom) IS NOT NULL
                  AND ST_DWithin(
                        COALESCE(b.entrance_geom, b.geom),
                        ST_SetSRID(ST_MakePoint(p_lng, p_lat), 4326)::geography,
                        p_radius_m
                  )
                ORDER BY distance_m ASC, b.id ASC
                LIMIT p_limit;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_is_store_open_wib(
                p_open_time TEXT,
                p_close_time TEXT,
                p_days TEXT,
                p_now TIMESTAMPTZ DEFAULT NOW()
            )
            RETURNS BOOLEAN
            LANGUAGE plpgsql
            STABLE
            AS \$\$
            DECLARE
                v_wib TIMESTAMP;
                v_dow INTEGER;
                v_days_lower TEXT;
                v_is_operating_day BOOLEAN;
                v_open_clean TEXT;
                v_close_clean TEXT;
                v_open_min INTEGER;
                v_close_min INTEGER;
                v_now_min INTEGER;
            BEGIN
                v_wib := COALESCE(p_now, NOW()) AT TIME ZONE 'Asia/Jakarta';
                v_dow := EXTRACT(DOW FROM v_wib)::INTEGER; -- 0=Sunday .. 6=Saturday
                v_days_lower := lower(btrim(COALESCE(p_days, 'Senin - Sabtu')));

                IF v_days_lower = ''
                    OR position('setiap hari' IN v_days_lower) > 0
                    OR position('senin - minggu' IN v_days_lower) > 0
                    OR position('senin-minggu' IN v_days_lower) > 0
                    OR position('24 jam' IN v_days_lower) > 0 THEN
                    v_is_operating_day := TRUE;
                ELSIF position('senin - jumat' IN v_days_lower) > 0
                    OR position('senin-jumat' IN v_days_lower) > 0 THEN
                    v_is_operating_day := (v_dow BETWEEN 1 AND 5);
                ELSE
                    v_is_operating_day := (v_dow <> 0);
                END IF;

                IF NOT v_is_operating_day THEN
                    RETURN FALSE;
                END IF;

                v_open_clean := replace(COALESCE(NULLIF(btrim(p_open_time), ''), '08:00'), '.', ':');
                v_close_clean := replace(COALESCE(NULLIF(btrim(p_close_time), ''), '21:00'), '.', ':');

                IF v_open_clean !~ '^\\d{1,2}:\\d{2}' THEN
                    v_open_clean := '08:00';
                END IF;
                IF v_close_clean !~ '^\\d{1,2}:\\d{2}' THEN
                    v_close_clean := '21:00';
                END IF;

                v_open_min := LEAST(23, GREATEST(0, split_part(v_open_clean, ':', 1)::INTEGER)) * 60
                            + LEAST(59, GREATEST(0, substring(split_part(v_open_clean, ':', 2) FROM 1 FOR 2)::INTEGER));
                v_close_min := LEAST(23, GREATEST(0, split_part(v_close_clean, ':', 1)::INTEGER)) * 60
                             + LEAST(59, GREATEST(0, substring(split_part(v_close_clean, ':', 2) FROM 1 FOR 2)::INTEGER));
                v_now_min := EXTRACT(HOUR FROM v_wib)::INTEGER * 60 + EXTRACT(MINUTE FROM v_wib)::INTEGER;

                IF v_close_min < v_open_min THEN
                    RETURN (v_now_min >= v_open_min OR v_now_min < v_close_min);
                END IF;

                RETURN (v_now_min >= v_open_min AND v_now_min < v_close_min);
            END;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_reset_all_pk_sequences()
            RETURNS TABLE(
                table_name TEXT,
                sequence_name TEXT,
                new_value BIGINT
            )
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_rec RECORD;
                v_seq TEXT;
                v_max BIGINT;
            BEGIN
                FOR v_rec IN
                    SELECT c.relname AS tbl
                    FROM pg_class c
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = 'public'
                      AND c.relkind IN ('r', 'p')
                      AND NOT c.relispartition
                      AND EXISTS (
                          SELECT 1
                          FROM pg_attribute a
                          WHERE a.attrelid = c.oid
                            AND a.attname = 'id'
                            AND NOT a.attisdropped
                      )
                    ORDER BY c.relname
                LOOP
                    v_seq := pg_get_serial_sequence(format('public.%I', v_rec.tbl), 'id');
                    IF v_seq IS NOT NULL THEN
                        EXECUTE format('SELECT COALESCE(MAX(id), 0) FROM public.%I', v_rec.tbl) INTO v_max;
                        IF v_max > 0 THEN
                            EXECUTE format('SELECT setval(%L, %s, true)', v_seq, v_max);
                        ELSE
                            EXECUTE format('SELECT setval(%L, 1, false)', v_seq);
                        END IF;

                        table_name := v_rec.tbl;
                        sequence_name := v_seq;
                        new_value := GREATEST(v_max, 1);
                        RETURN NEXT;
                    END IF;
                END LOOP;
            END;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_find_schedule_overlaps(
                p_room_id BIGINT,
                p_day TEXT,
                p_semester TEXT,
                p_start TIME,
                p_end TIME,
                p_exclude_id BIGINT DEFAULT NULL
            )
            RETURNS TABLE(
                schedule_id BIGINT,
                course_id BIGINT,
                class_group VARCHAR,
                time_start TIME,
                time_end TIME
            )
            LANGUAGE sql
            STABLE
            AS \$\$
                SELECT
                    s.id AS schedule_id,
                    s.course_id,
                    s.class_group,
                    s.time_start,
                    s.time_end
                FROM schedules s
                WHERE s.room_id = p_room_id
                  AND s.day = p_day
                  AND s.semester = p_semester
                  AND (p_exclude_id IS NULL OR s.id <> p_exclude_id)
                  AND int4range(
                        (EXTRACT(HOUR FROM s.time_start)::int * 60 + EXTRACT(MINUTE FROM s.time_start)::int),
                        (EXTRACT(HOUR FROM s.time_end)::int * 60 + EXTRACT(MINUTE FROM s.time_end)::int)
                      ) && int4range(
                        (EXTRACT(HOUR FROM p_start)::int * 60 + EXTRACT(MINUTE FROM p_start)::int),
                        (EXTRACT(HOUR FROM p_end)::int * 60 + EXTRACT(MINUTE FROM p_end)::int)
                      )
                ORDER BY s.time_start ASC, s.id ASC;
            \$\$
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS fn_find_schedule_overlaps(BIGINT, TEXT, TEXT, TIME, TIME, BIGINT)');
        DB::statement('DROP FUNCTION IF EXISTS fn_reset_all_pk_sequences()');
        DB::statement('DROP FUNCTION IF EXISTS fn_is_store_open_wib(TEXT, TEXT, TEXT, TIMESTAMPTZ)');
        DB::statement('DROP FUNCTION IF EXISTS fn_find_nearby_buildings(DOUBLE PRECISION, DOUBLE PRECISION, INTEGER, INTEGER)');
        DB::statement('DROP FUNCTION IF EXISTS fn_find_nearby_photocopy_places(DOUBLE PRECISION, DOUBLE PRECISION, INTEGER, INTEGER)');
        DB::statement('DROP FUNCTION IF EXISTS fn_generate_invoice_number(DATE)');
        DB::statement('DROP FUNCTION IF EXISTS fn_generate_order_code(DATE)');
        DB::statement('DROP SEQUENCE IF EXISTS seq_fotocopy_invoice_daily');
        DB::statement('DROP SEQUENCE IF EXISTS seq_fotocopy_order_daily');
    }
};
