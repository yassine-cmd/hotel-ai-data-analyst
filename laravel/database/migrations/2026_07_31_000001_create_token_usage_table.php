<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_usage', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 36);
            $table->string('client_id', 191);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 191)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('session_id');
        });

        Schema::table('session_metadata', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_tokens')->default(0)->after('turn_count');
            $table->unsignedInteger('prompt_tokens')->default(0)->after('total_tokens');
            $table->unsignedInteger('completion_tokens')->default(0)->after('prompt_tokens');
            $table->unsignedInteger('reasoning_tokens')->default(0)->after('completion_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('session_metadata', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'total_tokens', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens']);
        });

        Schema::dropIfExists('token_usage');
    }
};
