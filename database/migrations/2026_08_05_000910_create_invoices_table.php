<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_entity_id')->constrained('legal_entities');
            $table->foreignId('invoice_series_id')->constrained('invoice_series');
            $table->unsignedBigInteger('number');
            $table->string('full_number', 40);
            $table->string('kind', 16);
            $table->string('status', 12);
            $table->date('issue_date')->nullable();
            $table->foreignId('contract_id')->nullable()->constrained('contracts');
            $table->foreignId('contact_id')->constrained('contacts');
            $table->foreignId('rectifies_invoice_id')->nullable()->constrained('invoices');
            $table->string('rectification_reason', 64)->nullable();
            $table->string('issuer_name', 255);
            $table->string('issuer_tax_id', 64);
            $table->json('issuer_address');
            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_tax_id', 64)->nullable();
            $table->json('buyer_address')->nullable();
            $table->char('currency', 3);
            $table->decimal('net_total', 10, 2)->default('0');
            $table->decimal('tax_total', 10, 2)->default('0');
            $table->decimal('gross_total', 10, 2)->default('0');
            $table->string('verifactu_hash', 128)->nullable();
            $table->string('verifactu_prev_hash', 128)->nullable();
            $table->timestamp('verifactu_submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamps();
            $table->index(['legal_entity_id', 'issue_date'], 'invoices_entity_issued_idx');
            $table->unique(['invoice_series_id', 'number'], 'invoices_series_number_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
