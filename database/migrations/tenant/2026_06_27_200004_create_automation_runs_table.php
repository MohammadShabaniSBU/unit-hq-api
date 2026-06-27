<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')
                ->nullable()
                ->constrained('automations')
                ->nullOnDelete();

            // 'pending' | 'running' | 'succeeded' | 'failed' | 'cancelled'
            $table->string('status', 50)->default('pending');

            // What initiated this run: 'schedule' | 'event' | 'manual'
            $table->string('triggered_by', 100)->nullable();

            // The raw event payload that fired this run (object that was created/updated, etc.)
            $table->json('trigger_payload')->nullable();

            // Merged variable context bag shared across all steps in this run
            $table->json('context')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('automation_id');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
