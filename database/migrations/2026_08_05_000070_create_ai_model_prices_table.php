<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 24);
            $table->string('model', 128);
            $table->decimal('input_per_mtok', 10, 4);
            $table->decimal('cached_input_per_mtok', 10, 4)->nullable();
            $table->decimal('output_per_mtok', 10, 4);
            $table->char('currency', 3)->default('USD');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['provider', 'model', 'effective_from'], 'ai_model_prices_lookup_idx');
        });
        DB::table('ai_model_prices')->insert([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'input_per_mtok' => 3.0000,
            'cached_input_per_mtok' => 0.3000,
            'output_per_mtok' => 15.0000,
            'currency' => 'USD',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
