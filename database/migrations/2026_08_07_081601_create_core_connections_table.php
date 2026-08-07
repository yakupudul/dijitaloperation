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
        if (Schema::hasTable('core_connections')) {
            return;
        }

        Schema::create('core_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_connections');
    }
};
