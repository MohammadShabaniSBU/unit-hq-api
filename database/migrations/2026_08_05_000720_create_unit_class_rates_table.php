<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_class_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_class_id')->constrained('unit_classes')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['unit_class_id', 'site_id'], 'unit_class_rates_pairing_unique');
            $table->index(['unit_class_id', 'site_id'], 'unit_class_rates_unit_class_id_site_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_class_rates');
    }
};
