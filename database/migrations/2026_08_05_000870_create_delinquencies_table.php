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
        Schema::create('delinquencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('delinquency_policy_id')->constrained('delinquency_policies');
            $table->date('anchor_due_date');
            $table->date('opened_on');
            $table->date('cured_on')->nullable();
            $table->string('cure_trigger', 24)->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->text('paused_reason')->nullable();
            $table->foreignId('paused_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['contract_id', 'opened_on'], 'delinquencies_contract_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX delinquencies_open_idx ON delinquencies USING btree (contract_id) WHERE (cured_on IS NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquencies');
    }
};
