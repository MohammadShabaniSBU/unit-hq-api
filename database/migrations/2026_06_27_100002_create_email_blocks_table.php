<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->json('props')->default('{}');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index(['email_template_id', 'order']);
        });

        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('blocks');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->json('blocks')->default('[]');
        });

        Schema::dropIfExists('email_blocks');
    }
};
