<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_families', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16);
            $table->string('name', 128);
            $table->string('purpose', 24)->default('general');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'purpose']);
            $table->index('archived_at');
        });

        Schema::create('template_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_family_id')->constrained('template_families')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('subject', 500)->nullable();
            $table->json('blocks')->nullable();
            $table->text('legacy_html')->nullable();
            $table->text('body_text')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_family_id', 'locale'], 'tv_family_locale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_variants');
        Schema::dropIfExists('template_families');
    }
};
