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
            $table->smallInteger('tool_calls')->default('0');
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
            DB::statement('CREATE INDEX ai_usage_open_idx ON ai_usage_events USING btree (started_at) WHERE (settled_at IS NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
