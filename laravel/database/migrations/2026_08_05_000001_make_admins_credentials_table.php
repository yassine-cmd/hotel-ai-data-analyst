<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn the `admins` pivot table into a standalone credential table.
     *
     * Ordering matters: we backfill credential data BEFORE removing anything,
     * and we drop the `user_id` FK (and its on-delete cascade) BEFORE deleting
     * the now-redundant admin rows from `users`, so no admin is ever erased.
     */
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->after('id');
            $table->string('username', 255)->nullable()->after('name');
            $table->string('password', 255)->nullable()->after('username');
            $table->rememberToken();
        });

        // 1) Preserve every admin by copying its credential data from the linked user.
        DB::table('admins')->orderBy('id')->each(function ($admin) {
            $user = DB::table('users')->where('id', $admin->user_id)->first();
            if ($user) {
                DB::table('admins')->where('id', $admin->id)->update([
                    'name' => $user->name,
                    'username' => $user->username,
                    'password' => $user->password,
                    'remember_token' => $user->remember_token,
                ]);
            }
        });

        // 2) Credentials are now required.
        Schema::table('admins', function (Blueprint $table) {
            $table->string('name', 255)->nullable(false)->change();
            $table->string('username', 255)->nullable(false)->unique()->change();
            $table->string('password', 255)->nullable(false)->change();
        });

        // 3) Drop the pivot FK BEFORE touching `users` so the cascade cannot fire.
        $adminUserIds = DB::table('admins')->pluck('user_id');

        Schema::table('admins', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });

        // 4) The admin rows in `users` are now redundant (fully mirrored in `admins`).
        DB::table('users')->whereIn('id', $adminUserIds)->delete();

        // 5) Clean up any orphaned Sanctum tokens/sessions for the removed admin users.
        DB::table('personal_access_tokens')
            ->where('tokenable_type', App\Models\User::class)
            ->whereIn('tokenable_id', $adminUserIds)
            ->delete();

        DB::table('sessions')->whereIn('user_id', $adminUserIds)->delete();
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
        });

        // Recreate the removed admin `users` rows, preserving their credentials.
        DB::table('admins')->orderBy('id')->each(function ($admin) {
            $userId = DB::table('users')->insertGetId([
                'name' => $admin->name,
                'username' => $admin->username,
                'password' => $admin->password,
                'remember_token' => $admin->remember_token,
                'client_id' => null,
                'created_at' => $admin->created_at,
                'updated_at' => $admin->updated_at,
            ]);

            DB::table('admins')->where('id', $admin->id)->update(['user_id' => $userId]);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->unique()->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->dropColumn(['name', 'username', 'password', 'remember_token']);
        });
    }
};
