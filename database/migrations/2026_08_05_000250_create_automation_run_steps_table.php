<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('automation_nodes')->nullOnDelete();
            $table->string('node_type', 100);
            $table->string('status', 50)->default('pending');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
            $table->index('status', 'automation_run_steps_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_run_steps');
    }
};
