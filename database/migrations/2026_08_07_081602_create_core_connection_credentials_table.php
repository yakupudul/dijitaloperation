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
        if (Schema::hasTable('core_connection_credentials')) {
            return;
        }

        Schema::create('core_connection_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('core_connections')->cascadeOnDelete();
            $table->text('encrypted_payload');
            $table->timestamps();

            $table->unique('connection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_connection_credentials');
    }
};
