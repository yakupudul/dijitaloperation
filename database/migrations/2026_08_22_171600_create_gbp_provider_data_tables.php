<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_location_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->string('title')->nullable();
            $table->string('language_code', 32)->nullable();
            $table->string('store_code')->nullable();
            $table->string('place_id')->nullable();
            $table->text('maps_uri')->nullable();
            $table->string('primary_category')->nullable();
            $table->json('additional_categories')->nullable();
            $table->json('storefront_address')->nullable();
            $table->json('phone_numbers')->nullable();
            $table->text('website_uri')->nullable();
            $table->json('open_info')->nullable();
            $table->json('latlng')->nullable();
            $table->json('service_area')->nullable();
            $table->json('regular_hours')->nullable();
            $table->json('special_hours')->nullable();
            $table->json('more_hours')->nullable();
            $table->json('profile')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->json('google_updated')->nullable();
            $table->decimal('average_rating', 4, 2)->nullable();
            $table->unsignedBigInteger('total_review_count')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();
            $table->unique('run_id');
        });

        Schema::create('gbp_performance_daily', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->date('reporting_date')->index();
            $table->string('metric', 96)->index();
            $table->bigInteger('value');
            $table->timestampTz('collected_at');
            $table->timestampsTz();
            $table->unique(['external_resource_id', 'reporting_date', 'metric'], 'gbp_perf_resource_date_metric_uq');
        });

        Schema::create('gbp_search_keywords_monthly', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->date('month_start')->index();
            $table->text('search_keyword');
            $table->string('search_keyword_hash', 64);
            $table->bigInteger('impressions')->nullable();
            $table->bigInteger('threshold')->nullable();
            $table->timestampTz('collected_at');
            $table->timestampsTz();
            $table->unique(['external_resource_id', 'month_start', 'search_keyword_hash'], 'gbp_keyword_resource_month_hash_uq');
        });

        Schema::create('gbp_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->string('review_id');
            $table->json('reviewer')->nullable();
            $table->string('star_rating', 32)->nullable();
            $table->text('comment')->nullable();
            $table->timestampTz('create_time')->nullable()->index();
            $table->timestampTz('update_time')->nullable();
            $table->json('review_reply')->nullable();
            $table->json('review_media_items')->nullable();
            $table->text('review_reply_url')->nullable();
            $table->json('raw_payload');
            $table->timestampTz('collected_at');
            $table->timestampsTz();
            $table->unique(['external_resource_id', 'review_id'], 'gbp_review_resource_review_uq');
        });

        Schema::create('gbp_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->string('media_name');
            $table->string('media_format', 32)->nullable();
            $table->string('category', 96)->nullable();
            $table->text('google_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->timestampTz('create_time')->nullable()->index();
            $table->json('dimensions')->nullable();
            $table->json('location_association')->nullable();
            $table->json('raw_payload');
            $table->timestampTz('collected_at');
            $table->timestampsTz();
            $table->unique(['external_resource_id', 'media_name'], 'gbp_media_resource_name_uq');
        });

        Schema::create('gbp_posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->string('post_name');
            $table->string('language_code', 32)->nullable();
            $table->text('summary')->nullable();
            $table->string('topic_type', 64)->nullable();
            $table->string('state', 64)->nullable();
            $table->timestampTz('create_time')->nullable()->index();
            $table->timestampTz('update_time')->nullable();
            $table->json('call_to_action')->nullable();
            $table->json('media')->nullable();
            $table->json('event')->nullable();
            $table->json('offer')->nullable();
            $table->json('recurrence')->nullable();
            $table->json('raw_payload');
            $table->timestampTz('collected_at');
            $table->timestampsTz();
            $table->unique(['external_resource_id', 'post_name'], 'gbp_post_resource_name_uq');
        });

        Schema::create('gbp_attribute_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->json('attributes')->nullable();
            $table->json('google_updated_attributes')->nullable();
            $table->json('available_attributes')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();
            $table->unique('run_id');
        });

        Schema::create('gbp_service_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->json('service_items')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampsTz();
            $table->unique('run_id');
        });

        Schema::create('gbp_place_action_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->string('link_name');
            $table->string('provider_type', 96)->nullable();
            $table->boolean('is_editable')->nullable();
            $table->text('uri')->nullable();
            $table->string('place_action_type', 96)->nullable();
            $table->boolean('is_preferred')->nullable();
            $table->timestampTz('create_time')->nullable();
            $table->timestampTz('update_time')->nullable();
            $table->json('raw_payload');
            $table->timestampTz('collected_at');
            $table->timestampsTz();
            $table->unique(['external_resource_id', 'link_name'], 'gbp_action_resource_name_uq');
        });

        Schema::create('gbp_verification_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('digital_asset_id')->index();
            $table->unsignedBigInteger('external_resource_id')->index();
            $table->unsignedBigInteger('run_id')->index();
            $table->string('location_name');
            $table->boolean('has_voice_of_merchant')->nullable();
            $table->boolean('has_business_authority')->nullable();
            $table->json('voice_state')->nullable();
            $table->json('verifications')->nullable();
            $table->json('verification_options')->nullable();
            $table->string('verification_options_state', 64)->default('on_demand_only');
            $table->timestampTz('captured_at');
            $table->timestampsTz();
            $table->unique('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_verification_snapshots');
        Schema::dropIfExists('gbp_place_action_links');
        Schema::dropIfExists('gbp_service_snapshots');
        Schema::dropIfExists('gbp_attribute_snapshots');
        Schema::dropIfExists('gbp_posts');
        Schema::dropIfExists('gbp_media');
        Schema::dropIfExists('gbp_reviews');
        Schema::dropIfExists('gbp_search_keywords_monthly');
        Schema::dropIfExists('gbp_performance_daily');
        Schema::dropIfExists('gbp_location_snapshots');
    }
};
