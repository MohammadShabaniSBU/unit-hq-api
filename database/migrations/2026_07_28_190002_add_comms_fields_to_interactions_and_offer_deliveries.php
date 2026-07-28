<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traces a timeline row / delivery receipt back to the provider message and
 * the account that sent it, so inbound delivery webhooks can be reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->string('message_id')->nullable()->after('metadata');
            $table->foreignId('account_id')->nullable()->after('message_id')
                ->constrained('communication_accounts')->nullOnDelete();
        });

        Schema::table('offer_deliveries', function (Blueprint $table): void {
            $table->string('message_id')->nullable()->after('delivery_status');
            $table->foreignId('account_id')->nullable()->after('message_id')
                ->constrained('communication_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn('message_id');
        });

        Schema::table('offer_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn('message_id');
        });
    }
};
