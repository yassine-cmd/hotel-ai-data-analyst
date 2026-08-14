<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Admins now live in their own `admins` credentials table, so the
        // historical cleanup of client_id on admin user rows is no longer needed.
        // Kept as a no-op so fresh migrations (e.g. tests) remain runnable.
        if (method_exists(User::class, 'admin')) {
            User::whereHas('admin')->whereNotNull('client_id')->update(['client_id' => null]);
        }
    }

    public function down(): void
    {
    }
};
