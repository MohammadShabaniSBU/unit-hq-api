<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('notable_type', 255);
            $table->unsignedBigInteger('notable_id');
            $table->foreignId('employee_id')->constrained('employees');
            $table->text('content');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['notable_type', 'notable_id'], 'notes_notable_type_notable_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
