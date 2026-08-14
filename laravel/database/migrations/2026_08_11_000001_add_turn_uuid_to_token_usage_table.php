<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_usage', function (Blueprint $table) {
            $table->string('turn_uuid', 64)->nullable()->after('user_name');
            $table->unique(['client_id', 'session_id', 'turn_uuid'], 'token_usage_turn_unique');
        });
    }

    public function down(): void
    {
        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropUnique('token_usage_turn_unique');
            $table->dropColumn('turn_uuid');
        });
    }
};
