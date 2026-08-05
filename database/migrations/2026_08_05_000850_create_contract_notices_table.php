<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->string('notice_type', 32);
            $table->date('effective_date')->nullable();
            $table->date('required_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_channel', 24)->nullable();
            $table->string('sent_to', 255)->nullable();
            $table->string('document_ref', 255)->nullable();
            $table->text('short_notice_reason')->nullable();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['contract_id', 'notice_type'], 'contract_notices_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_notices');
    }
};
