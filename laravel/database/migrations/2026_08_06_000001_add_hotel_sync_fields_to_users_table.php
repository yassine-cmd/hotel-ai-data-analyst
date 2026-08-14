<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend users for the hotel user copy (discover + sync) mechanism.
     *
     * Adds the fields needed to mirror a hotel's login users: a stable external
     * id, the permission tokens, department, the source of the password hash,
     * and when the row was last synced. Usernames are scoped per client instead
     * of globally unique, and (client_id, external_id) becomes the idempotency
     * key for sync.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('external_id')->nullable();
            $table->json('permissions')->nullable();
            $table->string('department')->nullable();
            $table->string('password_hash_source')->default('local');
            $table->timestamp('last_synced_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->unique(['client_id', 'username']);
            $table->unique(['client_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'external_id']);
            $table->dropUnique(['client_id', 'username']);
            $table->unique('username');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_synced_at');
            $table->dropColumn('password_hash_source');
            $table->dropColumn('department');
            $table->dropColumn('permissions');
            $table->dropColumn('external_id');
        });
    }
};