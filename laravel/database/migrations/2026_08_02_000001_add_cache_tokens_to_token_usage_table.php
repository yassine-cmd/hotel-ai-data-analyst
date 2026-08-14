<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_usage', function (Blueprint $table) {
            $table->unsignedInteger('cache_hit_tokens')->default(0)->after('prompt_tokens');
            $table->unsignedInteger('cache_miss_tokens')->default(0)->after('cache_hit_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropColumn(['cache_hit_tokens', 'cache_miss_tokens']);
        });
    }
};
