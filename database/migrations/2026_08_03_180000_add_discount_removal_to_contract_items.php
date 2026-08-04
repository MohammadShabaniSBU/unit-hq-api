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
            $table->timestamp('discount_removed_at')->nullable()->after('discount_ends_at');
            $table->foreignId('discount_removed_by')->nullable()->after('discount_removed_at')
                ->constrained('employees')->nullOnDelete();
            $table->text('discount_removed_reason')->nullable()->after('discount_removed_by');
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_removed_by');
            $table->dropColumn(['discount_removed_at', 'discount_removed_reason']);
        });
    }
};
