<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('status')->default('new');
            $table->decimal('expected_value', 10, 2)->default(0);
            $table->date('expected_move_in')->nullable();
            $table->unsignedSmallInteger('expected_stay_length')->nullable();
            $table->string('expected_stay_period')->nullable();
            $table->text('storage_reason')->nullable();
            $table->decimal('desired_size', 8, 2)->nullable();
            $table->foreignId('desired_unit_class_id')->nullable()->constrained('unit_classes')->nullOnDelete();
            $table->text('intent_notes')->nullable();
            $table->timestamps();

            $table->index('contact_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
