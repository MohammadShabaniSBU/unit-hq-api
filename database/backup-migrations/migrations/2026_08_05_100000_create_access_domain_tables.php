<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access domain facts + grant cache (S15-00).
 * access_provider_account_id FK lands with S15-01's accounts table.
 * Partial uniques require PostgreSQL — skipped on SQLite like other indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_points', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('access_provider_account_id');
            $table->foreignId('site_id')->constrained('sites');
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->string('point_type', 16);
            $table->string('provider_point_id', 128);
            $table->string('label', 128);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('access_provider_account_id', 'ap_account_idx');
            $table->index('site_id', 'ap_site_idx');
        });

        Schema::create('access_suspensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts');
            $table->string('reason', 32);
            $table->foreignId('delinquency_id')->nullable()->constrained('delinquencies')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('lifted_at')->nullable();
            $table->unsignedBigInteger('lifted_by')->nullable();
            $table->string('lift_reason', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('contract_id', 'asus_contract_idx');
        });

        Schema::create('access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('access_point_id')->constrained('access_points');
            $table->foreignId('contact_id')->constrained('contacts');
            $table->foreignId('contract_id')->constrained('contracts');
            $table->string('provider_grant_id', 128)->nullable();
            $table->string('state', 16);
            $table->text('last_error')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['access_point_id', 'contact_id'], 'ag_point_contact_idx');
            $table->index('contract_id', 'ag_contract_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX ap_provider_idx ON access_points '
                .'(access_provider_account_id, provider_point_id) WHERE archived_at IS NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX ap_unit_idx ON access_points (unit_id) '
                .'WHERE unit_id IS NOT NULL AND archived_at IS NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX asus_active_idx ON access_suspensions (contract_id) '
                .'WHERE lifted_at IS NULL'
            );
            DB::statement(
                "CREATE UNIQUE INDEX ag_live_idx ON access_grants (access_point_id, contact_id) "
                ."WHERE state IN ('applying','applied')"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ag_live_idx');
            DB::statement('DROP INDEX IF EXISTS asus_active_idx');
            DB::statement('DROP INDEX IF EXISTS ap_unit_idx');
            DB::statement('DROP INDEX IF EXISTS ap_provider_idx');
        }

        Schema::dropIfExists('access_grants');
        Schema::dropIfExists('access_suspensions');
        Schema::dropIfExists('access_points');
    }
};
