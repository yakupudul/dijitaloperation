<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead quality loop (not a CRM): one row per client-side lead the operator registered (Meta lead form export,
 * website form export, WhatsApp, phone call…) keyed by (brand, lead source, lead id), with the outcome the clinic
 * reported back. No patient / deal / pipeline / appointment entity: only the outcome label, an optional value
 * in TRY, a short note and who marked it. Contact details are never stored — only a short matching hint
 * (initials + last digits of the phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('lead_source', 24);
            $table->string('lead_ref', 120);
            $table->timestampTz('lead_received_at');
            $table->string('campaign_label', 160)->nullable();
            $table->string('contact_hint', 40)->nullable();
            $table->string('status', 16)->default('new');
            $table->decimal('value_try', 14, 2)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('marked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['brand_id', 'lead_source', 'lead_ref'], 'lead_outcomes_identity_uq');
            $table->index(['brand_id', 'lead_received_at'], 'lead_outcomes_brand_received_idx');
            $table->index(['status', 'lead_received_at'], 'lead_outcomes_status_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_outcomes');
    }
};
