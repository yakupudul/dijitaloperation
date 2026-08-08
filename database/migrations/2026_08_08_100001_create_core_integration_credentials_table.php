<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_integration_credentials')) {
            return;
        }

        Schema::create('core_integration_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->string('credential_type', 32)->default('authorization');
            $table->text('encrypted_payload');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'credential_type'], 'core_integration_credentials_integration_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_integration_credentials');
    }
};
