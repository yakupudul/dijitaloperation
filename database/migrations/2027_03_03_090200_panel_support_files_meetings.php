<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri paneli › Destek (2026-11-28): formatted messages, any number of files on a message, the brand's own file
 * shelf copied to the brand's folder in the agency's Google Drive, and meeting requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_ticket_messages', function (Blueprint $table): void {
            // The message as cleaned HTML (bold, italic, lists, links); `body` keeps its plain text.
            $table->text('body_html')->nullable();
        });
        Schema::table('panel_tickets', function (Blueprint $table): void {
            // How the request arrived: panel, phone (the operator typed it after a call) or whatsapp.
            $table->string('origin', 20)->default('panel');
            $table->foreignId('opened_by_operator_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('panel_brand_settings', function (Blueprint $table): void {
            $table->string('drive_folder_id', 120)->nullable();
        });
        Schema::create('panel_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('panel_tickets')->nullOnDelete();
            $table->foreignId('ticket_message_id')->nullable()->constrained('panel_ticket_messages')->nullOnDelete();
            $table->foreignId('panel_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 190);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            // On the server until the copy in Drive stands; then only the Drive file is kept.
            $table->string('path')->nullable();
            $table->string('drive_file_id', 120)->nullable();
            $table->timestamp('drive_synced_at')->nullable();
            $table->string('drive_error', 300)->nullable();
            $table->timestamps();
            $table->index(['brand_id', 'created_at']);
        });
        Schema::create('panel_meetings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('panel_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->string('topic', 190);
            $table->string('preferred', 300)->nullable();
            $table->text('note')->nullable();
            // requested → planned → done; cancelled by either side.
            $table->string('status', 20)->default('requested');
            $table->timestamp('starts_at')->nullable();
            $table->string('meet_url', 500)->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_meetings');
        Schema::dropIfExists('panel_documents');
        Schema::table('panel_brand_settings', fn (Blueprint $table) => $table->dropColumn('drive_folder_id'));
        Schema::table('panel_tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opened_by_operator_id');
            $table->dropColumn('origin');
        });
        Schema::table('panel_ticket_messages', fn (Blueprint $table) => $table->dropColumn('body_html'));
    }
};
