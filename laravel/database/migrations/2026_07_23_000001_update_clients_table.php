<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('analytics_db_dsn')->nullable()->after('name');
            $table->string('analytics_admin_user')->nullable()->after('is_active');
            $table->text('analytics_admin_password')->nullable()->after('analytics_admin_user');
        });

        DB::table('clients')->whereNull('analytics_db_dsn')->get()->each(function ($client) {
            $dsn = $client->analytics_db_host . ':' . $client->analytics_db_port . '/' . $client->analytics_db_name;
            DB::table('clients')->where('id', $client->id)->update(['analytics_db_dsn' => $dsn]);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('analytics_db_user', 'analytics_agent_user');
            $table->renameColumn('analytics_db_password', 'analytics_agent_password');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['analytics_db_host', 'analytics_db_port', 'analytics_db_name']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('analytics_db_dsn')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('analytics_db_host')->default('localhost');
            $table->integer('analytics_db_port', false, true)->default(3306);
            $table->string('analytics_db_name');
        });

        DB::statement("UPDATE clients SET analytics_db_host = SUBSTRING_INDEX(analytics_db_dsn, ':', 1), analytics_db_port = SUBSTRING_INDEX(SUBSTRING_INDEX(analytics_db_dsn, ':', -1), '/', 1), analytics_db_name = SUBSTRING_INDEX(analytics_db_dsn, '/', -1)");

        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('analytics_agent_user', 'analytics_db_user');
            $table->renameColumn('analytics_agent_password', 'analytics_db_password');
            $table->dropColumn(['analytics_db_dsn', 'analytics_admin_user', 'analytics_admin_password']);
        });
    }
};
