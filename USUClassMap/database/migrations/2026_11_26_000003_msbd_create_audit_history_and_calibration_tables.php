<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_360_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->string('filename')->unique();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->double('pitch')->default(0);
            $table->double('yaw')->default(0);
            $table->double('hfov')->default(100);
            $table->jsonb('metadata')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('room_id');
            $table->index('building_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE TABLE audit_logs (
                    id BIGSERIAL,
                    table_name VARCHAR(100) NOT NULL,
                    record_id BIGINT NULL,
                    action VARCHAR(16) NOT NULL CHECK (action IN ('INSERT', 'UPDATE', 'DELETE')),
                    old_data JSONB NULL,
                    new_data JSONB NULL,
                    changed_by BIGINT NULL,
                    changed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    PRIMARY KEY (id, changed_at)
                ) PARTITION BY RANGE (changed_at)
            ");

            DB::statement("
                CREATE TABLE audit_logs_2026_q4 PARTITION OF audit_logs
                FOR VALUES FROM ('2026-10-01 00:00:00+07') TO ('2027-01-01 00:00:00+07')
            ");
            DB::statement("
                CREATE TABLE audit_logs_2027_q1 PARTITION OF audit_logs
                FOR VALUES FROM ('2027-01-01 00:00:00+07') TO ('2027-04-01 00:00:00+07')
            ");
            DB::statement("
                CREATE TABLE audit_logs_2027_q2 PARTITION OF audit_logs
                FOR VALUES FROM ('2027-04-01 00:00:00+07') TO ('2027-07-01 00:00:00+07')
            ");
            DB::statement('CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT');

            DB::statement('CREATE INDEX idx_audit_logs_table_rec_time ON audit_logs (table_name, record_id, changed_at DESC)');
            DB::statement('CREATE INDEX idx_audit_logs_changed_at ON audit_logs (changed_at DESC)');

            DB::statement("
                CREATE TABLE order_status_histories (
                    id BIGSERIAL,
                    order_id BIGINT NOT NULL,
                    order_code VARCHAR(64) NOT NULL,
                    old_order_status VARCHAR(50) NULL,
                    new_order_status VARCHAR(50) NULL,
                    old_payment_status VARCHAR(50) NULL,
                    new_payment_status VARCHAR(50) NULL,
                    changed_by BIGINT NULL,
                    note TEXT NULL,
                    changed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                    PRIMARY KEY (id, changed_at)
                ) PARTITION BY RANGE (changed_at)
            ");

            DB::statement("
                CREATE TABLE order_status_histories_2026 PARTITION OF order_status_histories
                FOR VALUES FROM ('2026-01-01 00:00:00+07') TO ('2027-01-01 00:00:00+07')
            ");
            DB::statement("
                CREATE TABLE order_status_histories_2027 PARTITION OF order_status_histories
                FOR VALUES FROM ('2027-01-01 00:00:00+07') TO ('2028-01-01 00:00:00+07')
            ");
            DB::statement('CREATE TABLE order_status_histories_default PARTITION OF order_status_histories DEFAULT');

            DB::statement('CREATE INDEX idx_osh_order_time ON order_status_histories (order_id, changed_at DESC)');
            DB::statement('CREATE INDEX idx_osh_code_time ON order_status_histories (order_code, changed_at DESC)');

            return;
        }

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('table_name', 100);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('action', 16);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['table_name', 'record_id', 'changed_at']);
        });

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_code', 64);
            $table->string('old_order_status', 50)->nullable();
            $table->string('new_order_status', 50)->nullable();
            $table->string('old_payment_status', 50)->nullable();
            $table->string('new_payment_status', 50)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['order_id', 'changed_at']);
            $table->index(['order_code', 'changed_at']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS order_status_histories CASCADE');
            DB::statement('DROP TABLE IF EXISTS audit_logs CASCADE');
        } else {
            Schema::dropIfExists('order_status_histories');
            Schema::dropIfExists('audit_logs');
        }

        Schema::dropIfExists('photo_360_calibrations');
    }
};
