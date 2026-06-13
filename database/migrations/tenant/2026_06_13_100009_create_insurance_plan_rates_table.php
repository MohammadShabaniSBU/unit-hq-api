<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_plan_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_plan_id')->constrained('insurance_plans')->cascadeOnDelete();
            $table->foreignId('price_id')->constrained('prices');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_plan_rates');
    }
};
