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
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('taskable_type', 255);
            $table->unsignedBigInteger('taskable_id');
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->constrained('employees');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('priority', 255)->default('medium');
            $table->string('status', 255)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remind_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('type', 255)->nullable();
            $table->timestamps();
            $table->index(['assigned_to', 'status'], 'tasks_assigned_to_status_index');
            $table->index(['due_at', 'status'], 'tasks_due_at_status_index');
            $table->index(['taskable_type', 'taskable_id'], 'tasks_taskable_type_taskable_id_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX tasks_remind_at_pending ON tasks USING btree (remind_at) WHERE ((remind_at IS NOT NULL) AND ((status)::text <> ALL ((ARRAY[\'done\'::character varying, \'cancelled\'::character varying])::text[])))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
