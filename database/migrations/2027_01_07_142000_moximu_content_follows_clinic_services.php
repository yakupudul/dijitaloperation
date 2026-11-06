<?php

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\ServiceCatalogItem;
use App\Models\Suggestion;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Moximu brand refresh, part 3 (yakup 2026-11-06, the brand page still showed the old agency): the archived services
 * kept their ★ and their clusters, so the content autopilot went on writing "Web Site Tasarım" / Ticimax articles
 * for moximu.com. Here: archived services lose the ★, the new clinic services get the phrases that assign searches to
 * them (what clinic owners type: "klinik seo", "google harita kaydı", "klinik reklam ajansı"…), the site's cluster
 * rows are rebuilt from the active services only, and content ideas of the archived services that were not sent yet
 * are dismissed (restorable on İçerik). Skipped in tests; apply() is called by the test.
 */
return new class extends Migration
{
    public const array PHRASES = [
        'Klinik SEO' => ['klinik seo', 'diş kliniği seo', 'doktor seo', 'hekim seo', 'hastane seo', 'muayenehane seo', 'estetik klinik seo', 'sağlık seo', 'diş hekimi seo'],
        'Google İşletme Profili Yönetimi' => ['google işletme profili', 'işletme profili yönetimi', 'google harita kaydı', 'google haritalar kayıt', 'google benim işletmem', 'klinik harita kaydı', 'google yorum yönetimi'],
        'Instagram ve Facebook Reklamları' => ['instagram reklam', 'facebook reklam', 'meta reklam', 'sosyal medya reklam', 'klinik instagram reklamı'],
        'Klinik Web Sitesi' => ['klinik web sitesi', 'diş kliniği web sitesi', 'doktor web sitesi', 'hastane web sitesi', 'muayenehane web sitesi', 'klinik web tasarım', 'doktor web tasarım', 'sağlık web sitesi'],
        'Yerel Klinik Paketi' => ['klinik reklam ajansı', 'sağlık reklam ajansı', 'diş kliniği reklam ajansı', 'klinik dijital pazarlama', 'sağlık dijital pazarlama', 'klinik pazarlama', 'doktor reklam ajansı'],
        'Sağlık Turizmi Paketi' => ['sağlık turizmi reklam', 'sağlık turizmi dijital pazarlama', 'sağlık turizmi ajansı', 'sağlık turizmi web sitesi', 'medical tourism marketing', 'health tourism marketing'],
    ];

    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $brand = Brand::query()->where('name', 'Moximu')->first();
        if ($brand === null) {
            return;
        }
        try {
            $this->apply($brand);
        } catch (Throwable $e) {
            Log::warning('Moximu content refresh failed.', ['error' => $e->getMessage()]);
        }
    }

    public function apply(Brand $brand): void
    {
        BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'archived')
            ->where(fn ($q) => $q->where('priority', 'main')->orWhere('is_priority', true)->orWhereNotNull('priority_rank'))
            ->update(['priority' => 'secondary', 'is_priority' => false, 'priority_rank' => null]);

        $active = BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)->where('status', 'active')->get();
        $keywords = app(ServiceKeywordService::class);
        foreach ($active as $offering) {
            $phrases = self::PHRASES[$offering->displayName()] ?? null;
            if ($phrases !== null && $offering->catalogItem instanceof ServiceCatalogItem) {
                try {
                    $keywords->append($offering->catalogItem, $phrases);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get() as $site) {
            try {
                app(ClusterPageMapper::class)->refresh($site, judge: false);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $serviceIds = $active->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $oldClusters = Cluster::query()->whereNotIn('service_id', $serviceIds ?: [0])->select('id');
        Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED, Suggestion::RECHECK])
            ->whereIn('cluster_id', $oldClusters)->get()
            ->reject(fn (Suggestion $s): bool => isset(((array) $s->action)['article_write_id']))
            ->each(fn (Suggestion $s) => $s->forceFill(['status' => Suggestion::DISMISSED])->save());
    }

    public function down(): void {}
};
