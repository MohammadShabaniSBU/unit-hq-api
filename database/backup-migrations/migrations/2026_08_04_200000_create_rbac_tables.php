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
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64);
            $table->string('label', 128);
            $table->text('description')->nullable();
            $table->string('scope_level', 16);
            $table->boolean('is_system')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX roles_key_idx ON roles (key)');

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission', 64);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX role_permissions_idx ON role_permissions (role_id, permission)');

        Schema::create('employee_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->foreignId('granted_by')->nullable()->constrained('employees');
            $table->timestamps();
        });

        DB::statement('CREATE INDEX employee_roles_employee_idx ON employee_roles (employee_id)');
        DB::statement('CREATE INDEX employee_roles_site_idx ON employee_roles (site_id)');
        DB::statement(
            'CREATE UNIQUE INDEX employee_roles_scoped_idx
             ON employee_roles (employee_id, role_id, site_id) WHERE site_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX employee_roles_company_idx
             ON employee_roles (employee_id, role_id) WHERE site_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
