<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only note log. No updated_at — notes are never edited.
 * Corrections are made by writing a new note. Attachable to Contact,
 * Deal, Offer, Contract, and Reservation via polymorphic relation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('notable_type');
            $table->unsignedBigInteger('notable_id');
            $table->foreignId('employee_id')->constrained('employees');
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['notable_type', 'notable_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
