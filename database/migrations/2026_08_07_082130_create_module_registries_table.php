<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('module_registries')) {
            return;
        }

        Schema::create('module_registries', function (Blueprint $table) {
            $table->id();
            $table->string('module_id')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('installed_version')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_registries');
    }
};
