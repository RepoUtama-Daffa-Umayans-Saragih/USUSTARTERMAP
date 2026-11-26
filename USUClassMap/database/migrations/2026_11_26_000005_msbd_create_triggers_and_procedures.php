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

        // 1. Spatial sync trigger functions (buildings & photocopy_places have entrance_lat/lng; fotocopy_stores does not)
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_trg_sync_geog_with_entrance()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF NEW.latitude IS NOT NULL AND NEW.longitude IS NOT NULL THEN
                    NEW.geom := ST_SetSRID(ST_MakePoint(NEW.longitude::double precision, NEW.latitude::double precision), 4326)::geography;
                ELSE
                    NEW.geom := NULL;
                END IF;

                IF NEW.entrance_lat IS NOT NULL AND NEW.entrance_lng IS NOT NULL THEN
                    NEW.entrance_geom := ST_SetSRID(ST_MakePoint(NEW.entrance_lng::double precision, NEW.entrance_lat::double precision), 4326)::geography;
                ELSE
                    NEW.entrance_geom := NEW.geom;
                END IF;

                RETURN NEW;
            END;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_trg_sync_geog_simple()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF NEW.latitude IS NOT NULL AND NEW.longitude IS NOT NULL THEN
                    NEW.geom := ST_SetSRID(ST_MakePoint(NEW.longitude::double precision, NEW.latitude::double precision), 4326)::geography;
                ELSE
                    NEW.geom := NULL;
                END IF;

                RETURN NEW;
            END;
            \$\$
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_sync_building_geom ON buildings');
        DB::statement('
            CREATE TRIGGER trg_sync_building_geom
            BEFORE INSERT OR UPDATE OF latitude, longitude, entrance_lat, entrance_lng ON buildings
            FOR EACH ROW
            EXECUTE FUNCTION fn_trg_sync_geog_with_entrance()
        ');

        DB::statement('DROP TRIGGER IF EXISTS trg_sync_photocopy_geom ON photocopy_places');
        DB::statement('
            CREATE TRIGGER trg_sync_photocopy_geom
            BEFORE INSERT OR UPDATE OF latitude, longitude, entrance_lat, entrance_lng ON photocopy_places
            FOR EACH ROW
            EXECUTE FUNCTION fn_trg_sync_geog_with_entrance()
        ');

        DB::statement('DROP TRIGGER IF EXISTS trg_sync_photocopy_geom ON fotocopy_stores');
        DB::statement('
            CREATE TRIGGER trg_sync_photocopy_geom
            BEFORE INSERT OR UPDATE OF latitude, longitude ON fotocopy_stores
            FOR EACH ROW
            EXECUTE FUNCTION fn_trg_sync_geog_simple()
        ');

        // 2. Auto-recalculate store starting_price on service price/availability change
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_trg_recalc_starting_price()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_store_id BIGINT;
                v_min INTEGER;
            BEGIN
                v_store_id := COALESCE(NEW.store_id, OLD.store_id);
                IF v_store_id IS NULL OR NOT EXISTS (SELECT 1 FROM fotocopy_stores WHERE id = v_store_id) THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(MIN(price), 200)
                INTO v_min
                FROM fotocopy_services
                WHERE store_id = v_store_id
                  AND is_available = TRUE;

                UPDATE fotocopy_stores
                SET starting_price = v_min,
                    updated_at = NOW()
                WHERE id = v_store_id
                  AND starting_price IS DISTINCT FROM v_min;

                RETURN NULL;
            END;
            \$\$
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_recalc_store_starting_price ON fotocopy_services');
        DB::statement('
            CREATE TRIGGER trg_recalc_store_starting_price
            AFTER INSERT OR UPDATE OF price, is_available OR DELETE ON fotocopy_services
            FOR EACH ROW
            EXECUTE FUNCTION fn_trg_recalc_starting_price()
        ');

        // 3. Auto-update Full-Text Search tsvector on catalog tables
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_trg_update_catalog_search_vector()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('indonesian', unaccent(coalesce(NEW.name, ''))), 'A') ||
                    setweight(to_tsvector('indonesian', unaccent(coalesce(NEW.brand, '') || ' ' || coalesce(NEW.sub_category, ''))), 'B') ||
                    setweight(to_tsvector('indonesian', unaccent(coalesce(NEW.category, '') || ' ' || coalesce(NEW.description, ''))), 'C');
                RETURN NEW;
            END;
            \$\$
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_update_catalog_search_vector ON fotocopy_services');
        DB::statement('
            CREATE TRIGGER trg_update_catalog_search_vector
            BEFORE INSERT OR UPDATE OF name, category, sub_category, brand, description ON fotocopy_services
            FOR EACH ROW
            EXECUTE FUNCTION fn_trg_update_catalog_search_vector()
        ');

        DB::statement('DROP TRIGGER IF EXISTS trg_update_catalog_search_vector ON photocopy_items');
        DB::statement('
            CREATE TRIGGER trg_update_catalog_search_vector
            BEFORE INSERT OR UPDATE OF name, category, sub_category, brand, description ON photocopy_items
            FOR EACH ROW
            EXECUTE FUNCTION fn_trg_update_catalog_search_vector()
        ');

        // 4. Critical mutation audit trail & order status history trigger
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_trg_audit_critical_mutations()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_old_json JSONB;
                v_new_json JSONB;
                v_record_id BIGINT;
                v_actor_raw TEXT;
                v_changed_by BIGINT;
            BEGIN
                v_old_json := CASE WHEN TG_OP IN ('UPDATE', 'DELETE') THEN to_jsonb(OLD) ELSE NULL END;
                v_new_json := CASE WHEN TG_OP IN ('INSERT', 'UPDATE') THEN to_jsonb(NEW) ELSE NULL END;
                v_record_id := COALESCE((v_new_json->>'id')::BIGINT, (v_old_json->>'id')::BIGINT);

                v_actor_raw := current_setting('app.current_user_id', true);
                v_changed_by := CASE
                    WHEN v_actor_raw IS NOT NULL AND v_actor_raw ~ '^[0-9]+$' THEN v_actor_raw::BIGINT
                    ELSE NULL
                END;

                INSERT INTO audit_logs (
                    table_name,
                    record_id,
                    action,
                    old_data,
                    new_data,
                    changed_by,
                    changed_at
                ) VALUES (
                    TG_TABLE_NAME,
                    v_record_id,
                    TG_OP,
                    v_old_json,
                    v_new_json,
                    v_changed_by,
                    NOW()
                );

                IF TG_TABLE_NAME = 'fotocopy_orders' THEN
                    IF TG_OP = 'INSERT' THEN
                        INSERT INTO order_status_histories (
                            order_id,
                            order_code,
                            old_order_status,
                            new_order_status,
                            old_payment_status,
                            new_payment_status,
                            changed_by,
                            note,
                            changed_at
                        ) VALUES (
                            v_record_id,
                            COALESCE(v_new_json->>'order_code', ''),
                            NULL,
                            v_new_json->>'order_status',
                            NULL,
                            v_new_json->>'payment_status',
                            COALESCE(v_changed_by, (v_new_json->>'user_id')::BIGINT),
                            'Order created',
                            NOW()
                        );
                    ELSIF TG_OP = 'UPDATE' AND (
                        (v_old_json->>'order_status') IS DISTINCT FROM (v_new_json->>'order_status')
                        OR (v_old_json->>'payment_status') IS DISTINCT FROM (v_new_json->>'payment_status')
                    ) THEN
                        INSERT INTO order_status_histories (
                            order_id,
                            order_code,
                            old_order_status,
                            new_order_status,
                            old_payment_status,
                            new_payment_status,
                            changed_by,
                            note,
                            changed_at
                        ) VALUES (
                            v_record_id,
                            COALESCE(v_new_json->>'order_code', v_old_json->>'order_code', ''),
                            v_old_json->>'order_status',
                            v_new_json->>'order_status',
                            v_old_json->>'payment_status',
                            v_new_json->>'payment_status',
                            COALESCE(v_changed_by, (v_new_json->>'user_id')::BIGINT),
                            'Status updated',
                            NOW()
                        );
                    END IF;
                END IF;

                RETURN NULL;
            END;
            \$\$
        ");

        foreach (['schedules', 'fotocopy_orders', 'fotocopy_stores', 'fotocopy_services'] as $auditTable) {
            DB::statement("DROP TRIGGER IF EXISTS trg_audit_critical_mutations ON {$auditTable}");
            DB::statement("
                CREATE TRIGGER trg_audit_critical_mutations
                AFTER INSERT OR UPDATE OR DELETE ON {$auditTable}
                FOR EACH ROW
                EXECUTE FUNCTION fn_trg_audit_critical_mutations()
            ");
        }

        // 5. Stored Procedures (atomic checkout, idempotent payment, dual-table reconciliation)
        DB::statement("
            CREATE OR REPLACE PROCEDURE sp_create_fotocopy_order_atomic(
                IN p_store_id BIGINT,
                IN p_user_id BIGINT,
                IN p_customer_name VARCHAR,
                IN p_customer_wa VARCHAR,
                IN p_payment_method VARCHAR,
                IN p_items_json JSONB,
                OUT p_order_id BIGINT,
                OUT p_order_code VARCHAR
            )
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_item JSONB;
                v_service_id BIGINT;
                v_qty INTEGER;
                v_unit_price INTEGER;
                v_subtotal INTEGER;
                v_srv fotocopy_services%ROWTYPE;
                v_total_price INTEGER := 0;
                v_item_name VARCHAR;
                v_category VARCHAR;
                v_prepared_items JSONB := '[]'::jsonb;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM fotocopy_stores WHERE id = p_store_id) THEN
                    RAISE EXCEPTION 'Toko fotokopi % tidak ditemukan', p_store_id;
                END IF;

                IF p_items_json IS NULL OR jsonb_typeof(p_items_json) <> 'array' OR jsonb_array_length(p_items_json) = 0 THEN
                    RAISE EXCEPTION 'Daftar item pesanan tidak boleh kosong';
                END IF;

                FOR v_item IN SELECT * FROM jsonb_array_elements(p_items_json)
                LOOP
                    v_service_id := NULLIF(v_item->>'service_id', '')::BIGINT;
                    v_qty := GREATEST(1, COALESCE(NULLIF(v_item->>'quantity', '')::INTEGER, 1));

                    IF v_service_id IS NOT NULL THEN
                        SELECT * INTO v_srv
                        FROM fotocopy_services
                        WHERE id = v_service_id AND store_id = p_store_id
                        FOR UPDATE;

                        IF NOT FOUND THEN
                            RAISE EXCEPTION 'Layanan/barang % tidak ditemukan di toko %', v_service_id, p_store_id;
                        END IF;

                        IF v_srv.stock IS NOT NULL THEN
                            IF v_srv.stock < v_qty THEN
                                RAISE EXCEPTION 'Stok % tidak mencukupi (tersedia %, diminta %)', v_srv.name, v_srv.stock, v_qty;
                            END IF;

                            UPDATE fotocopy_services
                            SET stock = stock - v_qty,
                                updated_at = NOW()
                            WHERE id = v_srv.id;
                        END IF;

                        v_unit_price := COALESCE(NULLIF(v_item->>'price', '')::INTEGER, v_srv.price);
                        v_item_name := COALESCE(NULLIF(v_item->>'service_name', ''), v_srv.name);
                        v_category := COALESCE(NULLIF(v_item->>'category', ''), v_srv.category);
                    ELSE
                        v_unit_price := GREATEST(0, COALESCE(NULLIF(v_item->>'price', '')::INTEGER, 0));
                        v_item_name := COALESCE(NULLIF(v_item->>'service_name', ''), 'Layanan Fotokopi');
                        v_category := COALESCE(NULLIF(v_item->>'category', ''), 'Print');
                    END IF;

                    v_subtotal := v_unit_price * v_qty;
                    v_total_price := v_total_price + v_subtotal;

                    v_prepared_items := v_prepared_items || jsonb_build_array(jsonb_build_object(
                        'service_id', v_service_id,
                        'service_name', v_item_name,
                        'category', v_category,
                        'price', v_unit_price,
                        'quantity', v_qty,
                        'subtotal', v_subtotal
                    ));
                END LOOP;

                p_order_code := fn_generate_order_code();

                INSERT INTO fotocopy_orders (
                    order_code,
                    store_id,
                    user_id,
                    customer_name,
                    customer_phone,
                    customer_whatsapp,
                    payment_method,
                    payment_status,
                    order_status,
                    wa_dispatch_status,
                    total_price,
                    created_at,
                    updated_at
                ) VALUES (
                    p_order_code,
                    p_store_id,
                    p_user_id,
                    p_customer_name,
                    p_customer_wa,
                    p_customer_wa,
                    COALESCE(NULLIF(p_payment_method, ''), 'qris'),
                    'pending',
                    'Menunggu',
                    'not_ready',
                    v_total_price,
                    NOW(),
                    NOW()
                )
                RETURNING id INTO p_order_id;

                INSERT INTO fotocopy_order_items (
                    order_id,
                    service_id,
                    service_name,
                    category,
                    price,
                    quantity,
                    subtotal,
                    created_at,
                    updated_at
                )
                SELECT
                    p_order_id,
                    NULLIF(elem->>'service_id', '')::BIGINT,
                    elem->>'service_name',
                    elem->>'category',
                    (elem->>'price')::INTEGER,
                    (elem->>'quantity')::INTEGER,
                    (elem->>'subtotal')::INTEGER,
                    NOW(),
                    NOW()
                FROM jsonb_array_elements(v_prepared_items) AS elem;
            END;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE PROCEDURE sp_mark_order_paid_atomic(
                IN p_order_code VARCHAR,
                IN p_payment_channel VARCHAR,
                IN p_payload JSONB,
                OUT p_was_already_paid BOOLEAN,
                OUT p_invoice_number VARCHAR
            )
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_order fotocopy_orders%ROWTYPE;
            BEGIN
                SELECT * INTO v_order
                FROM fotocopy_orders
                WHERE order_code = p_order_code
                FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Order % tidak ditemukan', p_order_code;
                END IF;

                IF v_order.payment_status = 'paid' THEN
                    p_was_already_paid := TRUE;
                    p_invoice_number := v_order.invoice_number;
                    RETURN;
                END IF;

                p_was_already_paid := FALSE;
                p_invoice_number := COALESCE(NULLIF(v_order.invoice_number, ''), fn_generate_invoice_number());

                UPDATE fotocopy_orders
                SET payment_status = 'paid',
                    payment_channel = COALESCE(p_payment_channel, payment_channel),
                    payment_payload = COALESCE(p_payload, payment_payload),
                    invoice_number = p_invoice_number,
                    paid_at = NOW(),
                    wa_dispatch_status = CASE
                        WHEN wa_dispatch_status IN ('sent', 'sandbox') THEN wa_dispatch_status
                        ELSE 'queued'
                    END,
                    updated_at = NOW()
                WHERE id = v_order.id;
            END;
            \$\$
        ");

        DB::statement("
            CREATE OR REPLACE PROCEDURE sp_sync_store_and_place_parity(
                IN p_store_id BIGINT DEFAULT NULL
            )
            LANGUAGE plpgsql
            AS \$\$
            DECLARE
                v_store RECORD;
                v_place_id BIGINT;
                v_open_time VARCHAR(20);
                v_close_time VARCHAR(20);
                v_gp_id VARCHAR(255);
            BEGIN
                FOR v_store IN
                    SELECT *
                    FROM fotocopy_stores
                    WHERE p_store_id IS NULL OR id = p_store_id
                    ORDER BY id
                LOOP
                    v_open_time := COALESCE(
                        NULLIF(substring(COALESCE(v_store.opening_hours, '') FROM '([0-9]{1,2}:[0-9]{2})'), ''),
                        '08:00'
                    );
                    v_close_time := COALESCE(
                        NULLIF(substring(COALESCE(v_store.opening_hours, '') FROM '[-–]\\s*([0-9]{1,2}:[0-9]{2})'), ''),
                        '21:00'
                    );

                    v_gp_id := v_store.google_place_id;
                    IF v_gp_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM photocopy_places pp2
                        WHERE pp2.google_place_id = v_gp_id AND pp2.slug <> v_store.slug
                    ) THEN
                        v_gp_id := NULL;
                    END IF;

                    INSERT INTO photocopy_places (
                        name,
                        slug,
                        description,
                        location_name,
                        latitude,
                        longitude,
                        entrance_lat,
                        entrance_lng,
                        google_maps_url,
                        google_maps_uri,
                        google_place_id,
                        open_time,
                        close_time,
                        operating_days,
                        phone,
                        whatsapp,
                        instagram,
                        facebook,
                        image_url,
                        rating,
                        user_rating_count,
                        is_active,
                        business_status,
                        is_verified,
                        last_synced_at,
                        created_at,
                        updated_at
                    ) VALUES (
                        v_store.name,
                        v_store.slug,
                        v_store.description,
                        v_store.location_name,
                        v_store.latitude,
                        v_store.longitude,
                        v_store.latitude,
                        v_store.longitude,
                        v_store.google_maps_url,
                        v_store.google_maps_url,
                        v_gp_id,
                        v_open_time,
                        v_close_time,
                        v_store.operating_days,
                        v_store.phone,
                        v_store.whatsapp,
                        v_store.instagram,
                        v_store.facebook,
                        v_store.image_url,
                        v_store.rating,
                        v_store.user_rating_count,
                        v_store.is_open,
                        CASE WHEN v_store.is_open THEN 'OPERATIONAL' ELSE 'CLOSED_TEMPORARILY' END,
                        TRUE,
                        NOW(),
                        COALESCE(v_store.created_at, NOW()),
                        NOW()
                    )
                    ON CONFLICT (slug) DO UPDATE SET
                        name = EXCLUDED.name,
                        description = COALESCE(EXCLUDED.description, photocopy_places.description),
                        location_name = EXCLUDED.location_name,
                        latitude = EXCLUDED.latitude,
                        longitude = EXCLUDED.longitude,
                        open_time = EXCLUDED.open_time,
                        close_time = EXCLUDED.close_time,
                        operating_days = EXCLUDED.operating_days,
                        phone = COALESCE(EXCLUDED.phone, photocopy_places.phone),
                        whatsapp = COALESCE(EXCLUDED.whatsapp, photocopy_places.whatsapp),
                        instagram = COALESCE(EXCLUDED.instagram, photocopy_places.instagram),
                        facebook = COALESCE(EXCLUDED.facebook, photocopy_places.facebook),
                        image_url = EXCLUDED.image_url,
                        rating = EXCLUDED.rating,
                        user_rating_count = EXCLUDED.user_rating_count,
                        is_active = EXCLUDED.is_active,
                        business_status = EXCLUDED.business_status,
                        last_synced_at = NOW(),
                        updated_at = NOW()
                    RETURNING id INTO v_place_id;

                    UPDATE photocopy_items pi
                    SET category = fs.category,
                        item_type = fs.item_type,
                        sub_category = fs.sub_category,
                        brand = fs.brand,
                        pack_qty = fs.pack_qty,
                        price = fs.price,
                        unit = fs.unit,
                        description = fs.description,
                        image_path = fs.image_path,
                        metadata = fs.metadata,
                        stock = fs.stock,
                        is_available = fs.is_available,
                        updated_at = NOW()
                    FROM fotocopy_services fs
                    WHERE fs.store_id = v_store.id
                      AND pi.photocopy_place_id = v_place_id
                      AND pi.name = fs.name;

                    INSERT INTO photocopy_items (
                        photocopy_place_id,
                        name,
                        category,
                        item_type,
                        sub_category,
                        brand,
                        pack_qty,
                        price,
                        unit,
                        description,
                        image_path,
                        metadata,
                        stock,
                        is_available,
                        created_at,
                        updated_at
                    )
                    SELECT
                        v_place_id,
                        fs.name,
                        fs.category,
                        fs.item_type,
                        fs.sub_category,
                        fs.brand,
                        fs.pack_qty,
                        fs.price,
                        fs.unit,
                        fs.description,
                        fs.image_path,
                        fs.metadata,
                        fs.stock,
                        fs.is_available,
                        COALESCE(fs.created_at, NOW()),
                        NOW()
                    FROM fotocopy_services fs
                    WHERE fs.store_id = v_store.id
                      AND NOT EXISTS (
                          SELECT 1
                          FROM photocopy_items pi
                          WHERE pi.photocopy_place_id = v_place_id
                            AND pi.name = fs.name
                      );
                END LOOP;
            END;
            \$\$
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP PROCEDURE IF EXISTS sp_sync_store_and_place_parity(BIGINT)');
        DB::statement('DROP PROCEDURE IF EXISTS sp_mark_order_paid_atomic(VARCHAR, VARCHAR, JSONB)');
        DB::statement('DROP PROCEDURE IF EXISTS sp_create_fotocopy_order_atomic(BIGINT, BIGINT, VARCHAR, VARCHAR, VARCHAR, JSONB)');

        foreach (['schedules', 'fotocopy_orders', 'fotocopy_stores', 'fotocopy_services'] as $auditTable) {
            DB::statement("DROP TRIGGER IF EXISTS trg_audit_critical_mutations ON {$auditTable}");
        }
        DB::statement('DROP TRIGGER IF EXISTS trg_update_catalog_search_vector ON photocopy_items');
        DB::statement('DROP TRIGGER IF EXISTS trg_update_catalog_search_vector ON fotocopy_services');
        DB::statement('DROP TRIGGER IF EXISTS trg_recalc_store_starting_price ON fotocopy_services');
        DB::statement('DROP TRIGGER IF EXISTS trg_sync_photocopy_geom ON fotocopy_stores');
        DB::statement('DROP TRIGGER IF EXISTS trg_sync_photocopy_geom ON photocopy_places');
        DB::statement('DROP TRIGGER IF EXISTS trg_sync_building_geom ON buildings');

        DB::statement('DROP FUNCTION IF EXISTS fn_trg_audit_critical_mutations()');
        DB::statement('DROP FUNCTION IF EXISTS fn_trg_update_catalog_search_vector()');
        DB::statement('DROP FUNCTION IF EXISTS fn_trg_recalc_starting_price()');
        DB::statement('DROP FUNCTION IF EXISTS fn_trg_sync_geog_simple()');
        DB::statement('DROP FUNCTION IF EXISTS fn_trg_sync_geog_with_entrance()');
    }
};
