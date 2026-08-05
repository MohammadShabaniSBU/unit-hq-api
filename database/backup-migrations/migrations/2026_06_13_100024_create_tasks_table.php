<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('taskable_type');
            $table->unsignedBigInteger('taskable_id');
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->constrained('employees');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority')->default('medium');
            $table->string('status')->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['taskable_type', 'taskable_id']);
            $table->index(['assigned_to', 'status']);
            $table->index(['due_at', 'status']);
        });

        // Partial index for the reminder scheduler — only pending, unfinished tasks.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE INDEX tasks_remind_at_pending ON tasks (remind_at) "
                . "WHERE remind_at IS NOT NULL AND status NOT IN ('done', 'cancelled')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
