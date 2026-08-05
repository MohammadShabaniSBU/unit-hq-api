<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')
                ->constrained('automations')
                ->cascadeOnDelete();

            // Stable identifier within the automation graph (e.g. "trigger_1", "action_2").
            // Used as the reference key in edges and execution context variables.
            $table->string('node_key', 100);

            // 'trigger' | 'action' | 'condition'
            $table->string('kind', 50);

            // 'property_update' | 'object_creation' | 'schedule' | 'update_object' | 'send_email'
            $table->string('type', 100);

            $table->string('label', 255);
            $table->text('description')->nullable();

            // Canvas position for the visual editor
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);

            // Typed config blob — structure depends on `type`
            $table->json('config');

            // Arbitrary extension bag for future use (tags, notes, ui hints, etc.)
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['automation_id', 'node_key']);
            $table->index('automation_id');
            $table->index('kind');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_nodes');
    }
};
