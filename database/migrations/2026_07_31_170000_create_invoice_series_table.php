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
        Schema::create('invoice_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities');
            $table->string('code', 20);
            $table->string('kind', 16);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('is_default')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index('archived_at');
            $table->index(['legal_entity_id', 'kind']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX invoice_series_code_idx ON invoice_series (legal_entity_id, code) WHERE archived_at IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX invoice_series_default_idx ON invoice_series (legal_entity_id, kind) WHERE is_default AND archived_at IS NULL'
        );

        $year = (int) now()->format('Y');
        $defaults = [
            ['code' => "F{$year}", 'kind' => 'ordinary'],
            ['code' => "S{$year}", 'kind' => 'simplified'],
            ['code' => "R{$year}", 'kind' => 'rectificative'],
        ];
        $now = now();

        foreach (DB::table('legal_entities')->pluck('id') as $entityId) {
            foreach ($defaults as $default) {
                DB::table('invoice_series')->insert([
                    'legal_entity_id' => $entityId,
                    'code' => $default['code'],
                    'kind' => $default['kind'],
                    'next_number' => 1,
                    'is_default' => true,
                    'archived_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
    }
};
