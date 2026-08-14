<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schema_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('table_name');
            $table->string('column_name')->nullable();
            $table->text('description')->nullable();
            $table->text('value_mappings')->nullable();
            $table->text('virtual_foreign_keys')->nullable();
            $table->boolean('is_sensitive')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schema_metadata');
    }
};
