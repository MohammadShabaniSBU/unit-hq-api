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
        Schema::create('deployment_identity', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->char('country_code', 2);
            $table->timestampTz('locked_at')->nullable();
            $table->timestamps();
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE deployment_identity ADD CONSTRAINT deployment_identity_singleton CHECK (id = 1)');
        } elseif ($driver === 'sqlite') {
            DB::statement('CREATE TRIGGER deployment_identity_singleton
                BEFORE INSERT ON deployment_identity
                WHEN NEW.id != 1
                BEGIN
                    SELECT RAISE(ABORT, "deployment_identity.id must be 1");
                END');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_identity');
    }
};
