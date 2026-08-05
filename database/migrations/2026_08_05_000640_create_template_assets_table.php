<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('hash', 64);
            $table->string('disk_path', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique('hash', 'template_assets_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_assets');
    }
};
