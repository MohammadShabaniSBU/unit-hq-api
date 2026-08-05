<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('filename', 255);
            $table->string('mime_type', 128);
            $table->integer('size_bytes');
            $table->string('disk_path', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->boolean('oversize')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
