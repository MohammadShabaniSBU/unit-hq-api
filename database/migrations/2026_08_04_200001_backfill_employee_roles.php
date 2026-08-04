<?php

declare(strict_types=1);

use App\Support\Auth\RbacEmployeeBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        RbacEmployeeBackfill::run();
    }

    public function down(): void
    {
        // Grants are additive history; reverse is a no-op. Column restore is the next migration's down.
    }
};
