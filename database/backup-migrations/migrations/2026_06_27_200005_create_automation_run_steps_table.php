<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')
                ->constrained('automation_runs')
                ->cascadeOnDelete();

            // Nullable — node may be deleted after the run was recorded
            $table->foreignId('node_id')
                ->nullable()
                ->constrained('automation_nodes')
                ->nullOnDelete();

            // Denormalized snapshot of the node type — safe for historical queries even after node deletion
            $table->string('node_type', 100);

            // 'pending' | 'running' | 'succeeded' | 'failed' | 'skipped'
            $table->string('status', 50)->default('pending');

            // Data passed into this node during execution
            $table->json('input')->nullable();

            // Data produced by this node (available as context vars for downstream nodes)
            $table->json('output')->nullable();

            // Error detail if status is 'failed' — { message, code, trace? }
            $table->json('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('run_id');
            $table->index('node_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_run_steps');
    }
};
