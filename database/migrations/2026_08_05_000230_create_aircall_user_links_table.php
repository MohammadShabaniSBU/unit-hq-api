<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aircall_user_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('aircall_user_id', 32);
            $table->string('aircall_user_label', 128);
            $table->timestamps();
            $table->unique('employee_id', 'aul_employee_idx');
            $table->unique('aircall_user_id', 'aul_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aircall_user_links');
    }
};
