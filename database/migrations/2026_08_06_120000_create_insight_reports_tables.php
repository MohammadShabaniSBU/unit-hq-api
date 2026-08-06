<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgres();
        } else {
            $this->createSqlite();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_report_params');
        Schema::dropIfExists('insight_reports');
    }

    private function createPostgres(): void
    {
        Schema::create('insight_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->string('source', 16);
            $table->string('native_key', 64)->nullable();
            $table->foreignId('analytics_account_id')->nullable()->constrained('analytics_accounts');
            $table->string('resource_kind', 16)->nullable();
            $table->string('resource_ref', 64)->nullable();
            $table->json('labels')->nullable();
            $table->json('description')->nullable();
            $table->string('icon', 48)->nullable();
            $table->string('section', 48)->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('visibility', 16)->default('all');
            $table->string('site_scope_mode', 16)->default('inherit');
            $table->json('options')->default('{}');
            $table->boolean('is_system')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['sort_order', 'id'], 'insight_reports_order_idx');
        });

        DB::statement(
            "ALTER TABLE insight_reports ADD CONSTRAINT insight_reports_native_shape CHECK (source <> 'native' OR native_key IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE insight_reports ADD CONSTRAINT insight_reports_embedded_shape CHECK (source <> 'embedded' OR (analytics_account_id IS NOT NULL AND resource_kind IS NOT NULL AND resource_ref IS NOT NULL))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX insight_reports_key_idx ON insight_reports (key) WHERE archived_at IS NULL'
        );

        Schema::create('insight_report_params', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insight_report_id')->constrained('insight_reports')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('value_source', 16);
            $table->json('static_value')->nullable();
            $table->string('dynamic_key', 64)->nullable();
            $table->string('binding', 16)->default('locked');
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['insight_report_id', 'name']);
        });

        DB::statement(
            "ALTER TABLE insight_report_params ADD CONSTRAINT insight_report_params_dynamic_locked CHECK (value_source <> 'dynamic' OR binding = 'locked')"
        );
        DB::statement(
            "ALTER TABLE insight_report_params ADD CONSTRAINT insight_report_params_dynamic_key CHECK (value_source <> 'dynamic' OR dynamic_key IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE insight_report_params ADD CONSTRAINT insight_report_params_static_value CHECK (value_source <> 'static' OR static_value IS NOT NULL)"
        );
    }

    private function createSqlite(): void
    {
        // SQLite requires CHECK constraints inline in CREATE TABLE.
        DB::statement('
            CREATE TABLE insight_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                key VARCHAR(64) NOT NULL,
                source VARCHAR(16) NOT NULL,
                native_key VARCHAR(64) NULL,
                analytics_account_id INTEGER NULL,
                resource_kind VARCHAR(16) NULL,
                resource_ref VARCHAR(64) NULL,
                labels TEXT NULL,
                description TEXT NULL,
                icon VARCHAR(48) NULL,
                section VARCHAR(48) NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                visibility VARCHAR(16) NOT NULL DEFAULT \'all\',
                site_scope_mode VARCHAR(16) NOT NULL DEFAULT \'inherit\',
                options TEXT NOT NULL DEFAULT \'{}\',
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                archived_at DATETIME NULL,
                created_by INTEGER NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (analytics_account_id) REFERENCES analytics_accounts (id),
                FOREIGN KEY (created_by) REFERENCES employees (id) ON DELETE SET NULL,
                CHECK (source <> \'native\' OR native_key IS NOT NULL),
                CHECK (source <> \'embedded\' OR (analytics_account_id IS NOT NULL AND resource_kind IS NOT NULL AND resource_ref IS NOT NULL))
            )
        ');

        DB::statement(
            'CREATE UNIQUE INDEX insight_reports_key_idx ON insight_reports (key) WHERE archived_at IS NULL'
        );
        DB::statement(
            'CREATE INDEX insight_reports_order_idx ON insight_reports (sort_order, id)'
        );

        DB::statement('
            CREATE TABLE insight_report_params (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                insight_report_id INTEGER NOT NULL,
                name VARCHAR(64) NOT NULL,
                value_source VARCHAR(16) NOT NULL,
                static_value TEXT NULL,
                dynamic_key VARCHAR(64) NULL,
                binding VARCHAR(16) NOT NULL DEFAULT \'locked\',
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                FOREIGN KEY (insight_report_id) REFERENCES insight_reports (id) ON DELETE CASCADE,
                UNIQUE (insight_report_id, name),
                CHECK (value_source <> \'dynamic\' OR binding = \'locked\'),
                CHECK (value_source <> \'dynamic\' OR dynamic_key IS NOT NULL),
                CHECK (value_source <> \'static\' OR static_value IS NOT NULL)
            )
        ');
    }
};
