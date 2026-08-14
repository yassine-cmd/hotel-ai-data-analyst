<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Ed25519 public key (64 hex chars) of the Laravel instance that
            // serves this client. Python verifies that instance's request
            // signatures against this key. NULL = instance not yet provisioned,
            // so it is rejected by Python (fail closed).
            $table->string('public_key', 64)->nullable()->after('analytics_admin_password');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('public_key');
        });
    }
};
