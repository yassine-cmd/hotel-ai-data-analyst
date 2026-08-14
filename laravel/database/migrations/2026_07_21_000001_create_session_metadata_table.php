<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 36);
            $table->string('client_id', 255);
            $table->string('name', 255)->default('');
            $table->integer('turn_count')->default(0);
            $table->json('context_window')->nullable();
            $table->string('path', 512);
            $table->dateTime('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->dateTime('last_access')->default(DB::raw('CURRENT_TIMESTAMP'))->useCurrentOnUpdate();
            $table->unique(['session_id', 'client_id']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_metadata');
    }
};
