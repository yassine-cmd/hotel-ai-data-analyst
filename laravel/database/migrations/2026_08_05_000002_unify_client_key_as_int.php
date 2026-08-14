<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // session_metadata: client_id was a string business key -> int FK to clients.id
        Schema::table('session_metadata', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'client_id']);
            $table->dropIndex(['client_id']);
            $table->unsignedBigInteger('client_internal_id')->nullable();
        });

        DB::statement('UPDATE session_metadata SET client_internal_id = (SELECT id FROM clients WHERE clients.client_id = session_metadata.client_id)');

        Schema::table('session_metadata', function (Blueprint $table) {
            $table->dropColumn('client_id');
            $table->renameColumn('client_internal_id', 'client_id');
            $table->index('client_id');
            $table->unique(['session_id', 'client_id']);
        });

        // token_usage: client_id was a string business key -> int FK to clients.id
        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'created_at']);
            $table->unsignedBigInteger('client_internal_id')->nullable();
        });

        DB::statement('UPDATE token_usage SET client_internal_id = (SELECT id FROM clients WHERE clients.client_id = token_usage.client_id)');

        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropColumn('client_id');
            $table->renameColumn('client_internal_id', 'client_id');
            $table->index(['client_id', 'created_at']);
        });

        // audit_logs: the redundant string business key is retired; client_internal_id is the only client ref
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('client_business_key');
        });

        // clients: the manually-entered string key is retired; id is the universal key
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
            $table->dropColumn('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('client_id')->nullable()->unique();
        });

        Schema::table('session_metadata', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->renameColumn('client_id', 'client_internal_id');
            $table->string('client_id', 255)->nullable()->after('session_id');
        });

        DB::statement('UPDATE session_metadata SET client_id = (SELECT client_id FROM clients WHERE clients.id = session_metadata.client_internal_id)');

        Schema::table('session_metadata', function (Blueprint $table) {
            $table->dropColumn('client_internal_id');
            $table->index('client_id');
            $table->unique(['session_id', 'client_id']);
        });

        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'created_at']);
            $table->renameColumn('client_id', 'client_internal_id');
            $table->string('client_id', 191)->nullable()->after('session_id');
        });

        DB::statement('UPDATE token_usage SET client_id = (SELECT client_id FROM clients WHERE clients.id = token_usage.client_internal_id)');

        Schema::table('token_usage', function (Blueprint $table) {
            $table->dropColumn('client_internal_id');
            $table->index(['client_id', 'created_at']);
        });
    }
};