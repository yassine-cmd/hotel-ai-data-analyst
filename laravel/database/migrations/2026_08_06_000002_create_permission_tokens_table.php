<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permission tokens mirror the hotel PMS permission vocabulary. Each token
     * carries its own table/column grants as JSON so the admin can edit a whole
     * token in one place and the runtime resolves a user's access by unioning
     * the grants of the tokens stored on the user row.
     */
    public function up(): void
    {
        Schema::create('permission_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('grants')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_tokens');
    }
};
