<?php

use App\Enums\StorageReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $allowed = array_map(
            fn (StorageReason $reason) => $reason->value,
            StorageReason::cases(),
        );

        DB::table('deals')
            ->whereNotNull('storage_reason')
            ->whereNotIn('storage_reason', $allowed)
            ->update(['storage_reason' => null]);
    }

    public function down(): void
    {
        // No-op: previous free-text values cannot be restored.
    }
};
