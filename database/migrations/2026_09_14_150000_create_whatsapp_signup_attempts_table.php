<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_signup_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_hash', 64);
            $table->string('settings_revision', 64);
            $table->string('mode', 30);
            $table->string('status', 30)->index();
            $table->string('step', 60)->nullable();
            $table->text('payload')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_signup_attempts');
    }
};
