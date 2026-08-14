<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_vocab', function (Blueprint $table) {
            $table->id();
            $table->string('term', 120);
            $table->string('definition', 500);
            $table->json('synonyms')->nullable();
            $table->string('example', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('term');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_vocab');
    }
};
