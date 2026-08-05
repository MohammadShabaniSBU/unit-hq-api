<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->after('rate')->constrained('discounts')->nullOnDelete();
            $table->decimal('base_rate', 10, 2)->nullable()->after('discount_id');
            $table->date('discount_ends_at')->nullable()->after('base_rate');
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_id');
            $table->dropColumn(['base_rate', 'discount_ends_at']);
        });
    }
};
