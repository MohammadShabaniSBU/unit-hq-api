<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('floor_name', 255);
            $table->text('svg_map');
            $table->smallInteger('sort_order')->default('0');
            $table->timestamps();
            $table->unique(['site_id', 'floor_name'], 'site_maps_site_id_floor_name_unique');
            $table->index(['site_id', 'sort_order'], 'site_maps_site_id_sort_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_maps');
    }
};
