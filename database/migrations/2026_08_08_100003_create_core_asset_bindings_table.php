<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_asset_bindings')) {
            return;
        }

        Schema::create('core_asset_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('capability');
            $table->string('status')->default('active');
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(
                ['digital_asset_id', 'external_resource_id'],
                'core_asset_bindings_unique_resource',
            );
            $table->unique(
                ['digital_asset_id', 'capability'],
                'core_asset_bindings_unique_capability',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_asset_bindings');
    }
};
