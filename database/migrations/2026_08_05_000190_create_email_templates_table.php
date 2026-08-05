<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->timestamps();
            $table->index('created_at', 'email_templates_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
