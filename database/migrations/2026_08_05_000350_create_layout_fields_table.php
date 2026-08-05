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
        Schema::create('layout_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('attribute_groups')->cascadeOnDelete();
            $table->string('entity_type', 255);
            $table->integer('display_order')->default(0);
            $table->string('field_type', 255);
            $table->string('native_field_key', 255)->nullable();
            $table->foreignId('attribute_definition_id')->nullable()->constrained('attribute_definitions')->cascadeOnDelete();
            $table->timestamps();
            $table->index('entity_type', 'layout_fields_entity_type_index');
            $table->index(['group_id', 'display_order'], 'layout_fields_group_id_display_order_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX layout_fields_entity_attribute_unique ON layout_fields USING btree (entity_type, attribute_definition_id) WHERE (attribute_definition_id IS NOT NULL)');
            DB::statement('CREATE UNIQUE INDEX layout_fields_entity_native_unique ON layout_fields USING btree (entity_type, native_field_key) WHERE (native_field_key IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('layout_fields');
    }
};
