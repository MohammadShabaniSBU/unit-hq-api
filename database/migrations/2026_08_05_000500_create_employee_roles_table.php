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
        Schema::create('employee_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->foreignId('granted_by')->nullable()->constrained('employees');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX employee_roles_company_idx ON employee_roles USING btree (employee_id, role_id) WHERE (site_id IS NULL)');
            DB::statement('CREATE UNIQUE INDEX employee_roles_scoped_idx ON employee_roles USING btree (employee_id, role_id, site_id) WHERE (site_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_roles');
    }
};
