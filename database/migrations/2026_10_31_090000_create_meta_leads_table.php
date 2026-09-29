<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 Faz 6 — Meta lead quality. One row per Meta lead of an ad account (imported from the Ads Manager lead
 * export), with the operator's manual mark: uygun · randevu · satış · uygunsuz. Contact details are never stored: only
 * the lead id, date, campaign / ad / form names. Counts per campaign are the CRM column of Ölçümleme, shown next to
 * (never merged with) Meta and GA4 results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('lead_ref', 64);
            $table->timestampTz('received_at')->nullable();
            $table->string('campaign_id', 40)->nullable();
            $table->string('campaign_name', 300)->nullable();
            $table->string('ad_name', 300)->nullable();
            $table->string('form_id', 40)->nullable();
            $table->string('form_name', 300)->nullable();
            $table->string('mark', 12)->nullable(); // uygun | randevu | satis | uygunsuz
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('marked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'lead_ref'], 'meta_leads_asset_ref_uq');
            $table->index(['digital_asset_id', 'received_at'], 'meta_leads_asset_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_leads');
    }
};
