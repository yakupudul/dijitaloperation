<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WhatsApp Business backup (Android msgstore.db.crypt15) the operator uploads in pieces and extracts with the
 * 64-digit key; its chats land in the WhatsApp inbox. The file and the key are removed once extraction ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_backup_imports')) {
            return;
        }
        Schema::create('whatsapp_backup_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('file_name');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->text('backup_key')->nullable();
            $table->json('stats')->nullable();
            $table->string('error', 1000)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_backup_imports');
    }
};
