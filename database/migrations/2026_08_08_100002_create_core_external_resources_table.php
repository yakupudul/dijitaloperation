<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_external_resources')) {
            return;
        }

        Schema::create('core_external_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->string('provider');
            $table->string('resource_type');
            $table->string('external_id');
            $table->string('display_name');
            $table->string('parent_external_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('available');
            $table->dateTime('discovered_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_id', 'resource_type', 'external_id'],
                'core_external_resources_unique_resource',
            );
            $table->index(['provider', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_external_resources');
    }
};
