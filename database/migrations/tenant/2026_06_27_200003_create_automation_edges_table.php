<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')
                ->constrained('automations')
                ->cascadeOnDelete();

            $table->foreignId('source_node_id')
                ->constrained('automation_nodes')
                ->cascadeOnDelete();

            $table->foreignId('target_node_id')
                ->constrained('automation_nodes')
                ->cascadeOnDelete();

            // Named port identifiers for multi-port nodes (e.g. "true" / "false" on a condition node)
            $table->string('source_handle', 100)->nullable();
            $table->string('target_handle', 100)->nullable();

            $table->string('label', 255)->nullable();

            // EdgeCondition — { type: 'always'|'filter', filterGroup?: FilterGroup }
            // Defaults to { type: 'always' } until conditional branching is implemented.
            $table->json('condition');

            $table->timestamps();

            $table->index('automation_id');
            $table->index('source_node_id');
            $table->index('target_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_edges');
    }
};
