<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meta_oauth_authorization_attempts')) {
            return;
        }

        Schema::create('meta_oauth_authorization_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('state_hash', 64)->unique();
            $table->json('requested_permissions');
            $table->string('login_config_id')->nullable();
            $table->string('return_route')->default('demo.integrations.meta');
            $table->json('return_params')->nullable();
            $table->string('status')->default('pending');
            $table->string('provider_error_code')->nullable();
            $table->string('safe_error_message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_oauth_authorization_attempts');
    }
};
