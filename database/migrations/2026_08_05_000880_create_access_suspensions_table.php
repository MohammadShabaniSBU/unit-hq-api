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
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX asus_active_idx ON access_suspensions USING btree (contract_id) WHERE (lifted_at IS NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_suspensions');
    }
};
