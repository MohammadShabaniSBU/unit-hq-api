<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->nullable();
            $table->string('company', 200)->nullable();
            $table->string('status', 255)->default('prospect');
            $table->string('contact_status', 255)->default('active');
            $table->foreignId('canonical_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('last_contacted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('billing_name', 255)->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->string('tax_id_type', 16)->nullable();
            $table->string('billing_address_line1', 255)->nullable();
            $table->string('billing_address_line2', 255)->nullable();
            $table->string('billing_city', 128)->nullable();
            $table->string('billing_postal_code', 32)->nullable();
            $table->char('billing_country_code', 2)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamps();
            $table->index('contact_status', 'contacts_contact_status_index');
            $table->unique('email', 'contacts_email_unique');
            $table->index('status', 'contacts_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
