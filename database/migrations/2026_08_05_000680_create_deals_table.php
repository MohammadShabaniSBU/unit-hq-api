<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('status', 255)->default('new');
            $table->date('expected_move_in')->nullable();
            $table->smallInteger('expected_stay_length')->nullable();
            $table->string('expected_stay_period', 255)->nullable();
            $table->string('storage_reason', 255)->nullable();
            $table->decimal('desired_size', 8, 2)->nullable();
            $table->foreignId('desired_unit_class_id')->nullable()->constrained('unit_classes')->nullOnDelete();
            $table->timestamps();
            $table->index('status', 'deals_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
