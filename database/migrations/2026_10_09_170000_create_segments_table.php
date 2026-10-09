<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('entity_type', 24)->default('contact');
            $table->jsonb('filter');
            $table->boolean('is_shared')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
