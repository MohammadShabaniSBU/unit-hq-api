<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public enquiry notes have no employee. Staff notes still set employee_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notes ALTER COLUMN employee_id DROP NOT NULL');

            return;
        }

        Schema::table('notes', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notes ALTER COLUMN employee_id SET NOT NULL');

            return;
        }

        Schema::table('notes', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
    }
};
