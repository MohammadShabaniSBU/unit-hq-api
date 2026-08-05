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
        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('definition_id')->constrained('attribute_definitions')->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 18, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->foreignId('value_option_id')->nullable()->constrained('attribute_options')->nullOnDelete();
            $table->timestamps();
            $table->unique(['definition_id', 'entity_id'], 'attribute_values_definition_id_entity_id_unique');
            $table->index(['definition_id', 'value_boolean'], 'attribute_values_definition_id_value_boolean_index');
            $table->index(['definition_id', 'value_date'], 'attribute_values_definition_id_value_date_index');
            $table->index(['definition_id', 'value_number'], 'attribute_values_definition_id_value_number_index');
            $table->index(['definition_id', 'value_option_id'], 'attribute_values_definition_id_value_option_id_index');
            $table->index(['entity_id', 'definition_id'], 'attribute_values_entity_id_definition_id_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX idx_av_date_nn ON attribute_values USING btree (definition_id, value_date) WHERE (value_date IS NOT NULL)');
            DB::statement('CREATE INDEX idx_av_number_nn ON attribute_values USING btree (definition_id, value_number) WHERE (value_number IS NOT NULL)');
            DB::statement('CREATE INDEX idx_av_option_nn ON attribute_values USING btree (definition_id, value_option_id) WHERE (value_option_id IS NOT NULL)');
            DB::statement('CREATE INDEX idx_av_text_trgm ON attribute_values USING gin (value_text gin_trgm_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
    }
};
