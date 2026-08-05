<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_template_id')->constrained('email_templates')->cascadeOnDelete();
            $table->string('type', 50);
            $table->json('props')->default('{}');
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->index(['email_template_id', 'order'], 'email_blocks_email_template_id_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_blocks');
    }
};
