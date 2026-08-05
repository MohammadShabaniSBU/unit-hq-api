<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->smallInteger('version')->default(1);
            $table->string('status', 50)->default('draft');
            $table->timestamp('archived_at')->nullable();
            $table->boolean('single_active_run_per_subject')->default(false);
            $table->json('default_guard')->nullable();
            // No DB FK: circular with playbooks.automation_id (FK lives on playbooks).
            $table->unsignedBigInteger('playbook_id')->nullable()->index();
            $table->timestamps();
            $table->index('archived_at', 'automations_archived_at_index');
            $table->index('created_at', 'automations_created_at_index');
            $table->index('status', 'automations_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
