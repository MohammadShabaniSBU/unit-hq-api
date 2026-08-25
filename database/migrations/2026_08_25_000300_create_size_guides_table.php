<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_guides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->foreignId('unit_class_id')->nullable()->constrained('unit_classes');
            $table->string('metric');
            $table->decimal('min_size', 8, 2)->nullable();
            $table->decimal('max_size', 8, 2)->nullable();
            $table->unsignedInteger('min_quantity')->nullable();
            $table->unsignedInteger('max_quantity')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['metric', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('size_guides');
    }
};
