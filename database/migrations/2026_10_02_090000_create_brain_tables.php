<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 7 (Beyin): method library overrides of rule thresholds, verification / snooze state on advisor items
 * and SEO tasks, and Google Ads Quality Score history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('method_settings', function (Blueprint $table): void {
            $table->string('config_key', 191)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (['advisor_items', 'seo_tasks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('verification', 20)->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->unsignedSmallInteger('reopened_count')->default(0);
                $table->timestamp('snoozed_until')->nullable();
            });
        }

        Schema::create('google_ads_quality_score_history', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->string('customer_id', 32);
            $table->string('ad_group_id', 32);
            $table->string('criterion_id', 32);
            $table->string('keyword_text', 255)->nullable();
            $table->date('observed_on');
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->string('ad_relevance', 30)->nullable();
            $table->string('landing_page_experience', 30)->nullable();
            $table->string('expected_ctr', 30)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'ad_group_id', 'criterion_id', 'observed_on'], 'gads_qs_history_unique');
            $table->index(['digital_asset_id', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_ads_quality_score_history');
        foreach (['advisor_items', 'seo_tasks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['verification', 'verified_at', 'reopened_count', 'snoozed_until']);
            });
        }
        Schema::dropIfExists('method_settings');
    }
};
