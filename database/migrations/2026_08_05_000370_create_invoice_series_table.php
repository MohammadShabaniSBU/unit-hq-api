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
        Schema::create('invoice_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities');
            $table->string('code', 20);
            $table->string('kind', 16);
            $table->unsignedBigInteger('next_number')->default('1');
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index('archived_at', 'invoice_series_archived_at_index');
            $table->index(['legal_entity_id', 'kind'], 'invoice_series_legal_entity_id_kind_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX invoice_series_code_idx ON invoice_series USING btree (legal_entity_id, code) WHERE (archived_at IS NULL)');
            DB::statement('CREATE UNIQUE INDEX invoice_series_default_idx ON invoice_series USING btree (legal_entity_id, kind) WHERE (is_default AND (archived_at IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
    }
};
