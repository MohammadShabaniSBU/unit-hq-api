<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->nullable();
            $table->string('company', 200)->nullable();

            $table->string('status')->default('prospect');
            $table->string('contact_status')->default('active');

            $table->foreignId('canonical_contact_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();

            $table->string('source', 100)->nullable();
            $table->string('source_detail', 255)->nullable();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->timestamp('last_contacted_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique('email');
            $table->index('status');
            $table->index('contact_status');
            $table->index('assigned_to');
            $table->index('canonical_contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
