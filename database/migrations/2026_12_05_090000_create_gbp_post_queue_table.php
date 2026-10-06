<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Otomatik İşletme Profili gönderileri (ADR-078): one row per location and day for the next 30 days, written from a page
 * of the brand's site from one angle, approved in bulk by the Admin and published on its day through the ADR-073 write.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gbp_post_queue')) {
            return;
        }
        Schema::create('gbp_post_queue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->string('angle', 16);
            $table->date('publish_on');
            $table->text('summary');
            $table->text('url')->nullable();
            $table->string('action_type', 16)->default('LEARN_MORE');
            $table->text('image_url')->nullable();
            $table->string('status', 16)->default('draft'); // draft | approved | published | skipped | failed | expired
            $table->string('note', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('external_write_action_id')->nullable()->constrained('external_write_actions')->nullOnDelete();
            $table->unsignedBigInteger('prompt_version_id')->nullable();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'publish_on'], 'gbp_post_queue_asset_day_idx');
            $table->index(['status', 'publish_on'], 'gbp_post_queue_status_day_idx');
            $table->index(['brand_id', 'page_id', 'angle'], 'gbp_post_queue_page_angle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_post_queue');
    }
};
