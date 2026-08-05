<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('insurance_id')->constrained('insurances')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->unique(['insurance_id', 'site_id'], 'insurance_rates_pairing_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_rates');
    }
};
