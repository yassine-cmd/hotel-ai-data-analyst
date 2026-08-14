<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The login path (AuthController) resolves a user by a bare username,
     * which the composite (client_id, username) index cannot serve, so every
     * login hits a full table scan. A standalone index on username fixes it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('username', 'users_username_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_username_index');
        });
    }
};