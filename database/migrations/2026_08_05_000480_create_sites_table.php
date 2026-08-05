<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->text('address')->nullable();
            $table->json('location')->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->string('timezone', 255)->default('UTC');
            $table->timestamp('archived_at')->nullable();
            $table->string('code', 255)->nullable();
            $table->string('postal_code', 255)->nullable();
            $table->string('state_region', 255)->nullable();
            $table->string('address_line_2', 255)->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignId('legal_entity_id')->constrained('legal_entities');
            $table->foreignId('delinquency_policy_id')->nullable()->constrained('delinquency_policies')->nullOnDelete();
            $table->timestamps();
            $table->index('archived_at', 'sites_archived_at_index');
            $table->unique('code', 'sites_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
