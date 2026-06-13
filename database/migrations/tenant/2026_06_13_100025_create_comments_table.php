<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only comment log. No updated_at — comments are never edited.
 * Corrections are made by writing a new comment. Attachable to Contact,
 * Deal, Task, and Reservation via polymorphic relation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->foreignId('employee_id')->constrained('employees');
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['commentable_type', 'commentable_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
