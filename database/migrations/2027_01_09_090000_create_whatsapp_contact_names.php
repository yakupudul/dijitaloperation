<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Names saved in the WhatsApp Business app's address book (coexistence contact sync, smb_app_state_sync). Kept apart
 * from the conversations because Meta sends the whole book, also for numbers that have no chat in MoxDOP yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_contact_names')) {
            return;
        }
        Schema::create('whatsapp_contact_names', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_id')->constrained('core_integrations')->cascadeOnDelete();
            $table->string('contact_id', 32);
            $table->string('name', 255);
            $table->timestamps();
            $table->unique(['integration_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contact_names');
    }
};
