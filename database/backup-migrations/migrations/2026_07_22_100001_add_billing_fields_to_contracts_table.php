<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadence + billing-anchor model + proration + deposit, snapshotted onto the
 * contract at signing. billing_anchor_date is derived once via
 * App\Support\Billing\BillingMath::resolveAnchorDate — never assigned move_in
 * directly. billed_through is a billing cursor the billing job advances, not
 * cached money (invariant #5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('billing_interval')->default('month')->after('deal_id');
            $table->smallInteger('billing_interval_count')->default(1)->after('billing_interval');
            $table->string('billing_anchor_model')->default('anniversary')->after('billing_interval_count');
            $table->date('billing_anchor_date')->nullable()->after('billing_anchor_model');
            $table->date('billed_through')->nullable()->after('billing_anchor_date');
            $table->string('proration_method')->default('daily')->after('billed_through');
            $table->date('move_in_date')->nullable()->after('proration_method');
            $table->decimal('deposit_amount', 10, 2)->default(0)->after('move_in_date');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'billing_interval',
                'billing_interval_count',
                'billing_anchor_model',
                'billing_anchor_date',
                'billed_through',
                'proration_method',
                'move_in_date',
                'deposit_amount',
            ]);
        });
    }
};
