<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playbook_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playbook_id')->constrained('playbooks')->cascadeOnDelete();
            $table->smallInteger('offset_days');
            $table->string('action', 32);
            $table->json('params')->default('{}');
            $table->smallInteger('sort');
            $table->timestamps();
            $table->unique(['playbook_id', 'sort'], 'ps_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playbook_steps');
    }
};
