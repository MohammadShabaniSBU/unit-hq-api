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
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('code', 255);
            $table->decimal('rate', 5, 2);
            $table->string('jurisdiction', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('employees');
            $table->timestamps();
            $table->index(['code', 'effective_from', 'effective_to'], 'tax_rates_code_effective_from_effective_to_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates USING btree (is_default) WHERE (is_default = true)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
