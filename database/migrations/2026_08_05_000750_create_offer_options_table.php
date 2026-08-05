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
        Schema::create('offer_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('unit_class_rate_id')->constrained('unit_class_rates');
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $table->string('label', 255);
            $table->text('description')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX offer_options_one_selected_per_offer ON offer_options USING btree (offer_id) WHERE (selected_at IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_options');
    }
};
