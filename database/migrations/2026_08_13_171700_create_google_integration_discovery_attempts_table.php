<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('google_integration_discovery_attempts')) {
            return;
        }

        Schema::create('google_integration_discovery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->string('connector');
            $table->string('status');
            $table->boolean('complete_inventory')->default(false);
            $table->unsignedInteger('resources_seen')->default(0);
            $table->unsignedInteger('resources_created')->default(0);
            $table->unsignedInteger('resources_updated')->default(0);
            $table->unsignedInteger('resources_unchanged')->default(0);
            $table->unsignedInteger('resources_marked_unavailable')->default(0);
            $table->string('error_category')->nullable();
            $table->string('safe_error_message')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'connector', 'created_at']);
            $table->index(['integration_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_integration_discovery_attempts');
    }
};
