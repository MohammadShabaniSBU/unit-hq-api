<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
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
            $table->string('issuer_name');
            $table->string('issuer_tax_id', 64);
            $table->json('issuer_address');
            $table->string('buyer_name')->nullable();
            $table->string('buyer_tax_id', 64)->nullable();
            $table->json('buyer_address')->nullable();
            $table->char('currency', 3);
            $table->decimal('net_total', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('gross_total', 10, 2)->default(0);
            $table->string('verifactu_hash', 128)->nullable();
            $table->string('verifactu_prev_hash', 128)->nullable();
            $table->timestamp('verifactu_submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamps();

            $table->unique(['invoice_series_id', 'number'], 'invoices_series_number_idx');
            $table->index('contract_id', 'invoices_contract_idx');
            $table->index(['legal_entity_id', 'issue_date'], 'invoices_entity_issued_idx');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('charge_id')->constrained('charges');
            $table->string('description');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('net_amount', 10, 2);
            $table->decimal('tax_rate_snapshot', 5, 2);
            $table->decimal('tax_amount', 10, 2);
            $table->decimal('gross_amount', 10, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('charge_id', 'invoice_lines_charge_idx');
        });

        Schema::table('charges', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('billing_period_id')->constrained('invoices');
        });
    }

    public function down(): void
    {
        Schema::table('charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });

        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
