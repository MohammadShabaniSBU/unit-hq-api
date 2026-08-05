<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playbooks', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);
            $table->string('name', 128);
            $table->boolean('is_active')->default(false);
            $table->json('enrolment_filters')->default('{}');
            $table->foreignId('automation_id')->nullable()->constrained('automations')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('is_active', 'playbooks_is_active_index');
            $table->index('kind', 'playbooks_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playbooks');
    }
};
