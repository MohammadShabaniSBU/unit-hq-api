<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_families', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->string('name', 128);
            $table->string('purpose', 24)->default('general');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('archived_at', 'template_families_archived_at_index');
            $table->index(['channel', 'purpose'], 'template_families_channel_purpose_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_families');
    }
};
