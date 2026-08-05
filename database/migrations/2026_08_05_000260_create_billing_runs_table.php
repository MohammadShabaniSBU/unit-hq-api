<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('trigger', 16);
            $table->date('horizon_date');
            $table->integer('contracts_considered')->default(0);
            $table->integer('contracts_billed')->default(0);
            $table->integer('contracts_skipped')->default(0);
            $table->integer('contracts_failed')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('employees');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
    }
};
