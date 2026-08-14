<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Billed cost per turn, as reported by the LLM gateway (OpenRouter
        // usage.cost). Null on rows recorded before cost reporting existed;
        // aggregates treat null as $0 (no backfill — dashboards are accurate
        // from the switch date forward).
        Schema::table('token_usage', function (Blueprint $table) {
            $table->decimal('cost_usd', 14, 8)->nullable()->after('completion_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropColumn('cost_usd');
        });
    }
};