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
        Schema::create('esign_envelopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('contract_document_id')->constrained('contract_documents')->restrictOnDelete();
            $table->foreignId('esign_provider_account_id')->constrained('esign_provider_accounts')->restrictOnDelete();
            $table->string('provider_envelope_ref', 128);
            $table->string('signer_name', 255);
            $table->string('signer_email', 255);
            $table->string('status', 16)->default('sent');
            $table->text('decline_reason')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('sent_at');
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->string('signed_pdf_path', 500)->nullable();
            $table->string('signed_pdf_sha256', 64)->nullable();
            $table->string('certificate_path', 500)->nullable();
            $table->boolean('completion_pending')->default(false);
            $table->boolean('post_cancellation')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index('provider_envelope_ref', 'ee_ref_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ee_open_idx ON esign_envelopes USING btree (contract_id) WHERE ((status)::text = ANY ((ARRAY[\'sent\'::character varying, \'viewed\'::character varying])::text[]))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_envelopes');
    }
};
