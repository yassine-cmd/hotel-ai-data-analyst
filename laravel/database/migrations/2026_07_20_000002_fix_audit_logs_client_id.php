<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->renameColumn('client_id', 'client_business_key');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('client_internal_id')->nullable()->constrained('clients', 'id')->nullOnDelete()->after('client_business_key');
        });

        DB::statement('UPDATE audit_logs SET client_internal_id = (SELECT id FROM clients WHERE clients.client_id = audit_logs.client_business_key)');
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['client_internal_id']);
            $table->dropColumn('client_internal_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->renameColumn('client_business_key', 'client_id');
        });
    }
};
