<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255);
            $table->string('password', 255)->nullable();
            $table->rememberToken();
            $table->string('first_name', 255);
            $table->string('last_name', 255);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique('email', 'employees_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
