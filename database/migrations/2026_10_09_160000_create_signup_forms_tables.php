<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('public_token', 48)->unique();
            $table->string('name', 128);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->json('subscription_list_ids');
            $table->string('channel', 16)->default('email');
            $table->json('fields');
            $table->string('consent_text_key', 64);
            $table->boolean('double_opt_in')->default(true);
            $table->foreignId('confirmation_template_family_id')->nullable()->constrained('template_families')->nullOnDelete();
            $table->string('success_redirect_url', 500)->nullable();
            $table->json('allowed_origins')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('signup_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signup_form_id')->constrained('signup_forms')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->json('consent_event_ids');
            $table->timestampTz('expires_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_confirmations');
        Schema::dropIfExists('signup_forms');
    }
};
