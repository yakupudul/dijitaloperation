<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_integration_discovery_contexts')) {
            return;
        }

        Schema::create('core_integration_discovery_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('external_resource_id')->constrained('core_external_resources')->cascadeOnDelete();
            $table->string('purpose')->default('discovery_context');
            $table->string('status')->default('active');
            $table->timestamp('selected_at');
            $table->timestamps();

            $table->unique(
                ['integration_id', 'external_resource_id', 'purpose'],
                'core_discovery_contexts_unique',
            );
            $table->index(['integration_id', 'purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_integration_discovery_contexts');
    }
};
