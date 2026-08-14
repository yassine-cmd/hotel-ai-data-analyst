<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_vocab', function (Blueprint $table) {
            $table->dropColumn('example');
        });
    }

    public function down(): void
    {
        Schema::table('custom_vocab', function (Blueprint $table) {
            $table->string('example', 200)->nullable()->after('synonyms');
        });
    }
};
