<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // Copy existing admin users into the admins table
        DB::table('users')->where('role', 'admin')->orderBy('id')->each(function ($user) {
            DB::table('admins')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('client_internal_id', 'client_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('client_id', 'client_internal_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 255)->default('client')->after('password');
        });

        DB::table('admins')->orderBy('user_id')->each(function ($admin) {
            DB::table('users')->where('id', $admin->user_id)->update(['role' => 'admin']);
        });

        Schema::dropIfExists('admins');
    }
};
