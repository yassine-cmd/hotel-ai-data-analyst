<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('budget_limit_usd', 12, 2)->nullable()->after('is_active');
            $table->timestamp('deactivated_at')->nullable()->after('budget_limit_usd');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['budget_limit_usd', 'deactivated_at']);
        });
    }
};
