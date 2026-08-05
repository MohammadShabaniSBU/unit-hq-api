<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AR-03 — AI usage metering: reserve/settle events + effective-dated model prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('call_id');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('conversation_id', 64)->nullable();
            $table->string('purpose', 32);
            $table->string('provider', 24)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('status', 16);
            $table->integer('input_tokens')->default(0);
            $table->integer('cached_input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('reasoning_tokens')->default(0);
            $table->boolean('tokens_estimated')->default(false);
            $table->unsignedSmallInteger('tool_calls')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('raw_usage')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique('call_id', 'ai_usage_call_idx');
            $table->index(['employee_id', 'started_at'], 'ai_usage_employee_idx');
            $table->index(['model', 'started_at'], 'ai_usage_model_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX ai_usage_open_idx ON ai_usage_events (started_at) WHERE settled_at IS NULL');
        }

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

        // Seed catalogue for the default Anthropic text model so cost derivation works in dev.
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
        Schema::dropIfExists('ai_usage_events');
    }
};
