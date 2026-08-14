<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schema_discovery_state', function (Blueprint $table) {
            $table->tinyInteger('id')->unsigned()->default(1)->primary();
            $table->timestamp('last_discovered_at')->nullable();
            $table->string('last_status', 50)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        DB::table('schema_discovery_state')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_discovery_state');
    }
};
