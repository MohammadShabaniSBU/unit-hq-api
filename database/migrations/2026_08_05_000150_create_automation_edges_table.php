<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('source_node_id')->constrained('automation_nodes')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('automation_nodes')->cascadeOnDelete();
            $table->string('source_handle', 100)->nullable();
            $table->string('target_handle', 100)->nullable();
            $table->string('label', 255)->nullable();
            $table->json('condition');
            $table->timestamps();
            $table->unique(['automation_id', 'source_node_id', 'source_handle'], 'automation_edges_source_handle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_edges');
    }
};
