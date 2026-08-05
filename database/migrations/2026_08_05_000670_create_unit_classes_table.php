<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_classes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 255);
            $table->string('label', 255);
            $table->decimal('size', 8, 2)->nullable();
            $table->foreignId('current_price_id')->nullable()->constrained('prices')->nullOnDelete();
            $table->string('tax_rate_code', 255)->nullable();
            $table->timestamps();
            $table->unique('code', 'unit_classes_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_classes');
    }
};
