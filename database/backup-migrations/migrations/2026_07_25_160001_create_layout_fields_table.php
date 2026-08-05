<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Overview card field placement (native + custom attributes).
 *
 * entity_type is denormalized from attribute_groups so partial unique indexes
 * can enforce "one placement per native key / definition per entity".
 *
 * NOTE: Partial indexes use raw SQL and require PostgreSQL.
 * On SQLite/MySQL the indexes are skipped; app validation still enforces uniqueness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layout_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('attribute_groups')->cascadeOnDelete();
            $table->string('entity_type')->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->string('field_type');
            $table->string('native_field_key')->nullable();
            $table->foreignId('attribute_definition_id')
                ->nullable()
                ->constrained('attribute_definitions')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'display_order']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX layout_fields_entity_native_unique '
                .'ON layout_fields (entity_type, native_field_key) '
                .'WHERE native_field_key IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX layout_fields_entity_attribute_unique '
                .'ON layout_fields (entity_type, attribute_definition_id) '
                .'WHERE attribute_definition_id IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('layout_fields');
    }
};
