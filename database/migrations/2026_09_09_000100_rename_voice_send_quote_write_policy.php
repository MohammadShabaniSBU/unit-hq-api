<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('agent_write_policies')
            ->where('tool_key', 'voice.send_quote_by_text')
            ->update(['tool_key' => 'sales.send_quote']);
    }

    public function down(): void
    {
        DB::table('agent_write_policies')
            ->where('tool_key', 'sales.send_quote')
            ->update(['tool_key' => 'voice.send_quote_by_text']);
    }
};
