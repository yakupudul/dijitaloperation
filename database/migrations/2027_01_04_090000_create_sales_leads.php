<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satış (yakup, 2026-11-04 "Hazırladığın satış sistemini kur"): one card per prospect (clinic or person) wherever the
 * request came from — WhatsApp chat, moximu.com form, All for Clinics audit, a shared site link, a phone call — with
 * its stage, the next step and its date, and a timeline of everything that happened. The phone's WhatsApp backup can
 * now also arrive by itself (phone_sync_token on the backup row: FolderSync puts the file on a WebDAV address).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('clinic')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('branch', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_key', 20)->nullable()->index();
            $table->string('website')->nullable();
            $table->string('website_host')->nullable()->index();
            $table->string('source', 20)->default('other')->index();
            $table->string('stage', 20)->default('new')->index();
            $table->string('lost_reason', 40)->nullable();
            $table->string('next_step')->nullable();
            $table->timestamp('next_step_at')->nullable()->index();
            $table->unsignedSmallInteger('followup_step')->default(0);
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('findings')->nullable();
            $table->string('findings_status', 20)->nullable();
            $table->timestamp('findings_at')->nullable();
            $table->text('draft')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamp('last_incoming_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_lead_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('sales_leads')->cascadeOnDelete();
            $table->string('kind', 20)->index();
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('happened_at')->index();
            $table->timestamps();
            $table->unique(['lead_id', 'fingerprint'], 'sales_lead_event_identity');
        });

        Schema::table('whatsapp_backup_lines', function (Blueprint $table): void {
            $table->text('phone_sync_token')->nullable();
            $table->timestamp('phone_sync_received_at')->nullable();
            $table->string('phone_sync_last', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_backup_lines', function (Blueprint $table): void {
            $table->dropColumn(['phone_sync_token', 'phone_sync_received_at', 'phone_sync_last']);
        });
        Schema::dropIfExists('sales_lead_events');
        Schema::dropIfExists('sales_leads');
    }
};
