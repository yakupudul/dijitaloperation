<?php

use App\Services\Site\BrandlessSites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * İçerik / SEO kapalı site (yakup, 2026-11-20): "hesap.moximu.com moximu.com'un mağaza alanı, burada içerik
 * yazılmayacak" and "randevu.drserapkorkmaz.com Google Ads landing sayfaları, burada SEO yapmayacağız". The websites
 * keep their brand (Ads, measurement, alarms, the build); MoxDOP stops every SEO and content job for them
 * (BrandlessSites::serviced). What was already waiting for them stops once, on deploy. Nothing is noindexed or deleted.
 */
return new class extends Migration
{
    private const array DOMAINS = ['hesap.moximu.com', 'randevu.drserapkorkmaz.com'];

    public function up(): void
    {
        if (! Schema::hasTable('digital_assets')) {
            return;
        }
        if (! Schema::hasColumn('digital_assets', 'seo_off')) {
            Schema::table('digital_assets', fn (Blueprint $table) => $table->boolean('seo_off')->default(false));
        }
        if (app()->runningUnitTests()) {
            return;
        }
        foreach (self::DOMAINS as $domain) {
            DB::table('digital_assets')->where('type', 'website')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereIn('domain', [$domain, 'www.'.$domain])
                    ->orWhere('primary_url', 'like', 'https://'.$domain.'%')->orWhere('primary_url', 'like', 'https://www.'.$domain.'%'))
                ->update(['seo_off' => true]);
        }
        BrandlessSites::forgetSeoOff();
        if (Schema::hasTable('external_write_actions') && Schema::hasTable('ai_tasks') && Schema::hasTable('content_site_states')) {
            BrandlessSites::stopPending();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('digital_assets') && Schema::hasColumn('digital_assets', 'seo_off')) {
            Schema::table('digital_assets', fn (Blueprint $table) => $table->dropColumn('seo_off'));
        }
    }
};
