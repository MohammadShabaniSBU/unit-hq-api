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
        Schema::create('delinquency_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delinquency_id')->constrained('delinquencies')->cascadeOnDelete();
            $table->foreignId('policy_step_id')->nullable()->constrained('delinquency_policy_steps')->nullOnDelete();
            $table->string('action', 32);
            $table->date('executed_on');
            $table->string('trigger', 16);
            $table->foreignId('charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->foreignId('unit_hold_id')->nullable()->constrained('unit_holds')->nullOnDelete();
            $table->foreignId('contract_notice_id')->nullable()->constrained('contract_notices')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->json('detail')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->foreignId('access_suspension_id')->nullable()->constrained('access_suspensions')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ds_once_idx ON delinquency_steps USING btree (delinquency_id, policy_step_id) WHERE (policy_step_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_steps');
    }
};
