<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('template_family_id')->constrained('template_families')->restrictOnDelete();
            $table->foreignId('template_variant_id')->constrained('template_variants')->restrictOnDelete();
            $table->timestamp('rendered_at');
            $table->string('pdf_path', 255);
            $table->string('sha256', 64);
            $table->string('status', 16)->default('draft');
            // No DB FK: circular with esign_envelopes.contract_document_id (FK lives on envelopes).
            $table->unsignedBigInteger('envelope_id')->nullable()->index();
            $table->timestamps();
            $table->index(['contract_id', 'status'], 'contract_documents_contract_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_documents');
    }
};
