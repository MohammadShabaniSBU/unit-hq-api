<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DISC-00 — reshape discounts into archive-only percent / free_time catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_options', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
        });

        Schema::drop('discounts');

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('kind', 16);
            $table->json('params');
            $table->string('applies_to', 16)->default('unit');
            $table->boolean('tracks_rate_changes')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('offer_options', function (Blueprint $table) {
            $table->foreign('discount_id')->references('id')->on('discounts')->nullOnDelete();
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->foreign('discount_id')->references('id')->on('discounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offer_options', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
        });

        Schema::drop('discounts');

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->index();
            $table->string('label');
            $table->string('discount_type');
            $table->decimal('value', 10, 2);
            $table->unsignedInteger('duration_months')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('offer_options', function (Blueprint $table) {
            $table->foreign('discount_id')->references('id')->on('discounts')->nullOnDelete();
        });

        Schema::table('contract_items', function (Blueprint $table) {
            $table->foreign('discount_id')->references('id')->on('discounts')->nullOnDelete();
        });
    }
};
