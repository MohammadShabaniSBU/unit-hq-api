<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts');
            $table->foreignId('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 255)->default('active');
            $table->timestamp('signed_at')->nullable();
            $table->string('billing_interval', 255)->default('month');
            $table->smallInteger('billing_interval_count')->default('1');
            $table->string('billing_anchor_model', 255)->default('anniversary');
            $table->date('billing_anchor_date')->nullable();
            $table->date('billed_through')->nullable();
            $table->string('proration_method', 255)->default('daily');
            $table->date('move_in_date')->nullable();
            $table->decimal('deposit_amount', 10, 2)->default('0');
            $table->char('currency', 3)->default('EUR');
            $table->date('notice_given_on')->nullable();
            $table->smallInteger('notice_period_days')->nullable();
            $table->date('move_out_on')->nullable();
            $table->string('ended_reason', 32)->nullable();
            $table->date('scheduled_move_out_on')->nullable();
            $table->string('move_out_settlement', 24)->nullable();
            $table->string('transfer_billing', 24)->default('prorate_immediately');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->boolean('autopay_enabled')->default(false);
            $table->smallInteger('rate_change_notice_days')->nullable();
            $table->timestamps();
            $table->index(['status', 'billed_through'], 'contracts_status_billed_through_idx');
            $table->index('status', 'contracts_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
