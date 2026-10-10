<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One issue number per list. Nulls stay free so drafts do not consume a number.
 * Cancelled issues keep their number, so they stay inside this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX campaigns_newsletter_issue_uidx ON campaigns (subscription_list_id, newsletter_issue_number) WHERE newsletter_issue_number IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex('campaigns_newsletter_issue_uidx');
        });
    }
};
