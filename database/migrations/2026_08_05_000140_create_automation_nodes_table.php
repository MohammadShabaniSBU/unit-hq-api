<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('node_key', 100);
            $table->string('kind', 50);
            $table->string('type', 100);
            $table->string('label', 255);
            $table->text('description')->nullable();
            $table->integer('position_x')->default(0);
            $table->integer('position_y')->default(0);
            $table->json('config');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['automation_id', 'node_key'], 'automation_nodes_automation_id_node_key_unique');
            $table->index('kind', 'automation_nodes_kind_index');
            $table->index('type', 'automation_nodes_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_nodes');
    }
};
