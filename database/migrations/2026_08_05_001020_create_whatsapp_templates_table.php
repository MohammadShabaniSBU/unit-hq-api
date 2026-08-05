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
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 128);
            $table->string('language', 8);
            $table->string('category', 16);
            $table->string('header_text', 60)->nullable();
            $table->text('body');
            $table->string('footer_text', 60)->nullable();
            $table->json('buttons')->nullable();
            $table->json('variables')->default('[]');
            $table->string('status', 16)->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->string('provider_template_id', 128)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->foreignId('communication_account_id')->constrained('communication_accounts');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX wt_identity_idx ON whatsapp_templates USING btree (communication_account_id, name, language) WHERE ((status)::text <> \'archived\'::text)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_templates');
    }
};
