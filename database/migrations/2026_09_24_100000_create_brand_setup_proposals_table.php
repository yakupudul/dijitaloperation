<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_setup_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('status', 24)->default('queued'); // queued|building|ready|applied|failed|discarded
            $table->text('website_url')->nullable();
            $table->json('items')->nullable();          // asset + binding proposals
            $table->json('services')->nullable();       // service proposals
            $table->string('services_status', 32)->nullable(); // ready|waiting_for_site|ai_unavailable|none
            $table->json('summary')->nullable();        // brand summary, sector suggestion, AI usage
            $table->json('apply_result')->nullable();
            $table->text('error_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->index(['brand_id', 'id'], 'brand_setup_proposals_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_setup_proposals');
    }
};
