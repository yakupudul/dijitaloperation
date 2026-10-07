<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strateji öner and Kütüphaneler:
 * - ad_campaign_stats.profile: each Meta campaign's recipe (settings, targeting of its biggest ad set, best ad),
 *   rebuilt daily with the 30-day row;
 * - ad_library_items: what the operator saved from a winning campaign (an ad text or a targeting) for later use,
 *   per catalog service and result type. Internal; nothing is sent anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_campaign_stats') && ! Schema::hasColumn('ad_campaign_stats', 'profile')) {
            Schema::table('ad_campaign_stats', function (Blueprint $table): void {
                $table->json('profile')->nullable();
            });
        }
        if (! Schema::hasTable('ad_library_items')) {
            Schema::create('ad_library_items', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 16); // text | targeting
                $table->string('channel', 16)->default('meta');
                $table->unsignedBigInteger('service_id')->nullable();
                $table->string('result_type', 16)->nullable();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete(); // source brand
                $table->unsignedBigInteger('digital_asset_id')->nullable();
                $table->string('campaign_id', 64)->nullable();
                $table->string('campaign_name', 300)->nullable();
                $table->string('title', 300);
                $table->json('payload');
                $table->decimal('spend', 14, 2)->nullable();
                $table->decimal('results', 12, 2)->nullable();
                $table->decimal('cpr', 14, 2)->nullable();
                $table->string('currency', 8)->nullable();
                $table->date('period_end')->nullable();
                $table->string('fingerprint', 64)->unique();
                $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['kind', 'service_id'], 'ad_library_items_service_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_library_items');
        if (Schema::hasTable('ad_campaign_stats') && Schema::hasColumn('ad_campaign_stats', 'profile')) {
            Schema::table('ad_campaign_stats', function (Blueprint $table): void {
                $table->dropColumn('profile');
            });
        }
    }
};
