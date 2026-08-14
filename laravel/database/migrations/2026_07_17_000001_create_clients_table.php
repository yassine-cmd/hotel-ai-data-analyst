<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->unique();
            $table->string('name');
            $table->string('analytics_db_host')->default('localhost');
            $table->integer('analytics_db_port', false, true)->default(3306);
            $table->string('analytics_db_name');
            $table->string('analytics_db_user');
            $table->text('analytics_db_password');
            $table->json('agent_style')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
