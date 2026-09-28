<?php

namespace App\Services\ContentStudio;

use App\Jobs\LocalizeContentArticleJob;
use App\Jobs\WriteContentArticleJob;
use App\Models\ContentArticle;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\SiteFixItem;
use App\Models\TopicCluster;
use App\Models\User;
use App\Services\Ai\AiCostEstimator;
use App\Services\Ai\AiRouteResolver;
use App\Services\ContentDelivery\ArticleDraft;
use App\Services\ContentDelivery\ContentDraftPublisher;
use App\Services\ContentDelivery\ContentExportService;
use App\Services\ContentDelivery\LanguageLinkMap;
use App\Services\SeoTasks\SeoText;
use App\Services\SiteFixes\SiteFixAi;
use App\Support\Ai\AiRouteKeys;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Faz 4 — İçerik Stüdyosu workflow: ideas → queued AI writing (with progress) → language versions (Polylang) →
 * post dates → WordPress drafts (ContentDraftPublisher, Admin approval, ADR-064 / ADR-076) or a WXR download. Both
 * delivery paths refuse articles that still break a blocking sector rule.
 */
final class ContentStudio
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly LanguageLinkMap $links,
    ) {}

    /**
     * Site languages for the studio: the source language (Polylang default) and the other languages.
     *
     * @return array{source: ?string, targets: list<array{slug: string, name: string}>}
     */
    public function languages(DigitalAsset $site): array
    {
        $languages = $this->links->languages($site);
        if (count($languages) < 2) {
            return ['source' => $languages[0]['slug'] ?? null, 'targets' => []];
        }
        $default = collect($languages)->firstWhere('default', true)['slug'] ?? $languages[0]['slug'];

        return ['source' => $default, 'targets' => array_values(array_map(fn (array $l): array => ['slug' => $l['slug'], 'name' => $l['name']],
            array_filter($languages, fn (array $l): bool => $l['slug'] !== $default)))];
    }

    /**
     * Estimated AI cost of writing N articles (+ localization into the given languages), before the operator confirms.
     *
     * @return array{total: ?float, label: ?string}
     */
    public function estimate(int $articles, int $languages = 0): array
    {
        $estimator = app(AiCostEstimator::class);
        $write = $estimator->cost(AiRouteKeys::CONTENT_ARTICLE, (int) config('moxdop-content.article.estimate_input_tokens', 4500), (int) config('moxdop-content.article.estimate_output_tokens', 3200));
        $localize = $languages > 0 ? $estimator->cost(AiRouteKeys::CONTENT_LOCALIZE, (int) config('moxdop-content.article.localize_input_tokens', 5000), (int) config('moxdop-content.article.localize_output_tokens', 3200)) : 0.0;
        if ($write === null || $localize === null) {
            return ['total' => null, 'label' => null];
        }
        // A compliance re-prompt can double one call: the estimate counts one extra call for every fourth article.
        $total = $articles * 1.25 * ($write + $languages * $localize);

        return ['total' => round($total, 4), 'label' => $total <= 0 ? 'ücretsiz model' : '~$'.number_format(max($total, 0.0001), $total < 0.01 ? 4 : 2, ',', '.')];
    }

    /**
     * "Hazırla" / "Seçilenleri hazırla (N)": one article row per idea (status `writing`), one queued job each.
     *
     * @param  list<int>  $ideaIds
     * @param  list<string>  $languages  target languages (other than the source language)
     * @return array{batch: string, queued: int}
     */
    public function queueWrite(DigitalAsset $site, array $ideaIds, array $languages, ?User $user): array
    {
        app(ServiceScope::class)->ensureAssetServed($site, 'studio');
        if ($this->routes->resolve(AiRouteKeys::CONTENT_ARTICLE)->isEmpty()) {
            throw ValidationException::withMessages(['studio' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        $max = (int) config('moxdop-content.bulk.max_articles', 60);
        $ideas = ContentIdea::query()->where('digital_asset_id', $site->id)->whereIn('id', $ideaIds ?: [0])->whereIn('status', ['open', 'written'])->orderBy('sort')->orderBy('id')->get();
        if ($ideas->isEmpty()) {
            throw ValidationException::withMessages(['studio' => 'Hazırlanacak konu seçilmedi.']);
        }
        if ($ideas->count() > $max) {
            throw ValidationException::withMessages(['studio' => 'Tek seferde en fazla '.$max.' yazı hazırlanabilir.']);
        }
        $siteLanguages = $this->languages($site);
        $targets = array_values(array_intersect(array_map('strtolower', $languages), array_column($siteLanguages['targets'], 'slug')));
        $batch = (string) Str::uuid();
        $queued = 0;
        foreach ($ideas as $idea) {
            $busy = ContentArticle::query()->where('content_idea_id', $idea->id)->whereNull('source_article_id')->whereIn('status', ['writing', 'ready', 'needs_fix', 'sent', 'exported', 'published'])->exists();
            if ($busy) {
                continue;
            }
            $article = ContentArticle::query()->create([
                'brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'content_idea_id' => $idea->id, 'topic_cluster_id' => $idea->topic_cluster_id,
                'language' => $siteLanguages['source'] !== null && $siteLanguages['targets'] !== [] ? $siteLanguages['source'] : null,
                'status' => 'writing', 'title' => $idea->title, 'translate_to' => $targets, 'batch' => $batch, 'created_by' => $user?->id,
            ]);
            if ($article->language !== null) {
                $article->forceFill(['translation_key' => $article->reference()])->save();
            }
            $idea->forceFill(['status' => 'writing'])->save();
            WriteContentArticleJob::dispatch($article->id);
            $queued++;
        }
        if ($queued === 0) {
            throw ValidationException::withMessages(['studio' => 'Seçilen konuların yazısı zaten var ya da yazılıyor.']);
        }

        return ['batch' => $batch, 'queued' => $queued];
    }

    /** "Yeniden yaz": the AI writes the article again (its violations are passed on). */
    public function rewrite(ContentArticle $article): void
    {
        app(ServiceScope::class)->ensureAssetServed($article->digital_asset_id, 'studio');
        if (! in_array($article->status, ['ready', 'needs_fix', 'failed'], true)) {
            throw ValidationException::withMessages(['studio' => 'Bu yazı şu an yeniden yazılamaz ('.$article->statusLabel().').']);
        }
        $article->forceFill(['status' => 'writing', 'error' => null])->save();
        $article->source_article_id === null ? WriteContentArticleJob::dispatch($article->id) : LocalizeContentArticleJob::dispatch($article->id);
    }

    /** Job: write one article, then create and queue its language versions. */
    public function runWrite(int $articleId): void
    {
        $article = ContentArticle::query()->find($articleId);
        if ($article === null || $article->status !== 'writing') {
            return;
        }
        try {
            app(ArticleWriter::class)->write($article);
        } catch (Throwable $exception) {
            report($exception);
            $article->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            ContentIdea::query()->whereKey($article->content_idea_id)->where('status', 'writing')->update(['status' => 'open']);

            return;
        }
        foreach ((array) $article->translate_to as $language) {
            $translation = ContentArticle::query()->firstOrCreate(['source_article_id' => $article->id, 'language' => (string) $language], [
                'brand_id' => $article->brand_id, 'digital_asset_id' => $article->digital_asset_id, 'content_idea_id' => $article->content_idea_id,
                'topic_cluster_id' => $article->topic_cluster_id, 'translation_key' => $article->translation_key ?? $article->reference(),
                'status' => 'writing', 'title' => $article->title, 'batch' => $article->batch, 'scheduled_at' => $article->scheduled_at, 'created_by' => $article->created_by,
            ]);
            if ($translation->wasRecentlyCreated || in_array($translation->status, ['failed'], true)) {
                $translation->forceFill(['status' => 'writing'])->save();
                LocalizeContentArticleJob::dispatch($translation->id);
            }
        }
    }

    /** Job: localize one language version. */
    public function runLocalize(int $articleId): void
    {
        $article = ContentArticle::query()->find($articleId);
        if ($article === null || $article->status !== 'writing' || $article->source_article_id === null) {
            return;
        }
        try {
            app(ArticleWriter::class)->localize($article);
        } catch (Throwable $exception) {
            report($exception);
            $article->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();
        }
    }

    /** "Diğer dillerde de hazırla" for an already written article. @param  list<string>  $languages */
    public function translate(ContentArticle $source, array $languages): int
    {
        app(ServiceScope::class)->ensureAssetServed($source->digital_asset_id, 'studio');
        $site = DigitalAsset::query()->findOrFail($source->digital_asset_id);
        $targets = array_values(array_intersect(array_map('strtolower', $languages), array_column($this->languages($site)['targets'], 'slug')));
        if ($source->source_article_id !== null || ! $source->hasDraft() || $targets === [] || $source->language === null) {
            throw ValidationException::withMessages(['studio' => 'Bu yazı için çeviri hazırlanamaz (sitede Polylang dilleri ve yazılmış bir kaynak metin gerekli).']);
        }
        if ($this->routes->resolve(AiRouteKeys::CONTENT_LOCALIZE)->isEmpty()) {
            throw ValidationException::withMessages(['studio' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        $source->forceFill(['translate_to' => array_values(array_unique(array_merge((array) $source->translate_to, $targets))), 'translation_key' => $source->translation_key ?? $source->reference()])->save();
        $count = 0;
        foreach ($targets as $language) {
            $translation = ContentArticle::query()->firstOrCreate(['source_article_id' => $source->id, 'language' => $language], [
                'brand_id' => $source->brand_id, 'digital_asset_id' => $source->digital_asset_id, 'content_idea_id' => $source->content_idea_id,
                'topic_cluster_id' => $source->topic_cluster_id, 'translation_key' => $source->translation_key, 'status' => 'writing', 'title' => $source->title,
                'batch' => $source->batch, 'scheduled_at' => $source->scheduled_at,
            ]);
            if ($translation->wasRecentlyCreated || in_array($translation->status, ['failed', 'needs_fix', 'ready'], true)) {
                $translation->forceFill(['status' => 'writing'])->save();
                LocalizeContentArticleJob::dispatch($translation->id);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Post dates: the chosen articles in order, from the start date, N per day at the given time (site time zone).
     * Language versions get the date of their source. Drafts stay drafts.
     *
     * @param  list<int>  $articleIds
     */
    public function schedule(DigitalAsset $site, array $articleIds, string $startDate, string $time, int $perDay): int
    {
        $tz = (string) config('moxdop-content.schedule.timezone', 'Europe/Istanbul');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1 || $perDay < 1 || $perDay > 10) {
            throw ValidationException::withMessages(['schedule' => 'Başlangıç tarihi, saat ve günlük adet geçerli olmalı (günde 1–10 yazı).']);
        }
        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $startDate.' '.$time, $tz);
        } catch (Throwable) {
            throw ValidationException::withMessages(['schedule' => 'Tarih okunamadı.']);
        }
        if ($start === null || $start === false) {
            throw ValidationException::withMessages(['schedule' => 'Tarih okunamadı.']);
        }
        $articles = ContentArticle::query()->where('digital_asset_id', $site->id)->whereNull('source_article_id')->whereIn('id', $articleIds ?: [0])
            ->whereNotIn('status', ['sent', 'published'])->orderBy('id')->get()
            ->sortBy(fn (ContentArticle $a): int => array_search($a->id, array_map('intval', $articleIds), true))->values();
        $spacing = $perDay > 1 ? intdiv(8 * 60, $perDay) : 0;
        foreach ($articles as $i => $article) {
            $date = $start->addDays(intdiv($i, $perDay))->addMinutes(($i % $perDay) * $spacing)->utc();
            $article->forceFill(['scheduled_at' => $date])->save();
            ContentArticle::query()->where('source_article_id', $article->id)->update(['scheduled_at' => $date, 'updated_at' => now()]);
        }

        return $articles->count();
    }

    /** "WordPress'e taslak gönder": source + ready language versions as one approved write (ADR-076). */
    public function publish(User $user, ContentArticle $source): ExternalWriteAction
    {
        app(ServiceScope::class)->ensureAssetServed($source->digital_asset_id, 'write');
        if ($source->source_article_id !== null) {
            $source = ContentArticle::query()->findOrFail($source->source_article_id);
        }
        $this->assertDeliverable($source);
        $site = DigitalAsset::query()->where('type', 'website')->findOrFail($source->digital_asset_id);
        $translations = $source->translations()->get()->filter(fn (ContentArticle $t): bool => in_array($t->status, ContentArticle::DELIVERABLE, true) && $t->hasDraft());
        $drafts = [];
        foreach ($translations as $translation) {
            $drafts[] = $translation->draft();
        }
        $action = app(ContentDraftPublisher::class)->publish($user, $site, $source->draft(), $drafts);
        $ids = array_merge([$source->id], $translations->pluck('id')->all());
        ContentArticle::query()->whereIn('id', $ids)->update(['status' => 'sent', 'external_write_action_id' => $action->id, 'updated_at' => now()]);

        return $action;
    }

    /**
     * Articles for a WXR file: each chosen source with its deliverable language versions.
     *
     * @param  list<int>  $articleIds
     * @return array{drafts: list<ArticleDraft>, ids: list<int>}
     */
    public function exportDrafts(DigitalAsset $site, array $articleIds): array
    {
        $sources = ContentArticle::query()->where('digital_asset_id', $site->id)->whereNull('source_article_id')->whereIn('id', $articleIds ?: [0])->orderBy('scheduled_at')->orderBy('id')->limit(200)->get();
        $drafts = [];
        $ids = [];
        foreach ($sources as $source) {
            $this->assertDeliverable($source);
            $drafts[] = $source->translation_key === null && $source->translations()->exists() ? $source->forceFill(['translation_key' => $source->reference()])->draft() : $source->draft();
            $ids[] = $source->id;
            foreach ($source->translations()->get() as $translation) {
                if (in_array($translation->status, ContentArticle::DELIVERABLE, true) && $translation->hasDraft()) {
                    $drafts[] = $translation->draft();
                    $ids[] = $translation->id;
                }
            }
        }

        return ['drafts' => $drafts, 'ids' => $ids];
    }

    /**
     * @param  list<int>  $articleIds
     * @return array{xml: string, filename: string, count: int}
     */
    public function exportWxr(DigitalAsset $site, array $articleIds): array
    {
        $set = $this->exportDrafts($site, $articleIds);
        $export = app(ContentExportService::class)->wxr($site, $set['drafts']);
        ContentArticle::query()->whereIn('id', $set['ids'])->where('status', 'ready')->update(['status' => 'exported', 'exported_at' => now(), 'updated_at' => now()]);
        ContentArticle::query()->whereIn('id', $set['ids'])->whereNull('exported_at')->update(['exported_at' => now()]);

        return $export;
    }

    /**
     * "Güncelleme taslağı hazırla" for a weakly covered topic: an ADR-070 page-text proposal (site fix `content_update`)
     * for the owner page with the missing queries / sections / FAQ as brief; the AI rewrite runs queued and the operator
     * reviews and sends it from Düzeltmeler (draft copy first, a second approval replaces the live page).
     */
    public function prepareUpdate(TopicCluster $cluster): SiteFixItem
    {
        app(ServiceScope::class)->ensureAssetServed($cluster->digital_asset_id, 'studio');
        if (blank($cluster->owner_url) || ! in_array($cluster->verdict, ['strengthen', 'none', 'merge'], true)) {
            throw ValidationException::withMessages(['studio' => 'Bu konunun güçlendirilecek bir sayfası yok.']);
        }
        $objectId = $this->wordPressObjectId((int) $cluster->digital_asset_id, (string) $cluster->owner_url);
        if ($objectId === null) {
            throw ValidationException::withMessages(['studio' => 'Sayfa WordPress envanterinde bulunamadı (MoxDOP Connector gerekli). Eklenecek bölümler: '
                .implode(', ', array_slice((array) data_get($cluster->verdict_detail, 'missing_queries', []), 0, 6)).'.']);
        }
        $detail = (array) $cluster->verdict_detail;
        $item = SiteFixItem::query()->updateOrCreate(['digital_asset_id' => $cluster->digital_asset_id, 'item_key' => hash('sha256', 'content_update|studio|'.$objectId)], [
            'brand_id' => $cluster->brand_id, 'type' => 'content_update', 'phase' => SiteFixItem::TYPES['content_update'][1], 'object_id' => $objectId,
            'url' => mb_substr((string) $cluster->owner_url, 0, 1000), 'label' => mb_substr((string) ($cluster->owner_title ?: $cluster->owner_url), 0, 255),
            'reason' => 'İçerik Stüdyosu: “'.$cluster->label.'” konusu zayıf karşılanıyor. '.($detail['reason'] ?? ''),
            'current' => ['source' => 'studio', 'topic_cluster_id' => $cluster->id, 'brief' => [
                'topic' => $cluster->label, 'add_queries' => array_values((array) ($detail['missing_queries'] ?? [])),
                'add_sections' => array_values((array) ($detail['sections'] ?? [])), 'add_faq' => array_values((array) ($detail['faq'] ?? [])),
            ]],
            'status' => 'open',
        ]);
        app(SiteFixAi::class)->queue(SiteFixAi::KIND_PAGE, $item->id);

        return $item->refresh();
    }

    private function wordPressObjectId(int $siteId, string $url): ?string
    {
        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return null;
        }
        $key = SeoText::urlKey($url);
        foreach (DB::table('website_cms_object_snapshot')->where('digital_asset_id', $siteId)->whereIn('object_type', ['page', 'post'])->where('status', 'publish')
            ->orderByDesc('observed_at')->limit(20000)->get(['object_id', 'permalink']) as $row) {
            if (filled($row->permalink) && SeoText::urlKey((string) $row->permalink) === $key) {
                return (string) $row->object_id;
            }
        }

        return null;
    }

    private function assertDeliverable(ContentArticle $article): void
    {
        if (! $article->hasDraft()) {
            throw ValidationException::withMessages(['write' => '"'.mb_substr($article->title, 0, 60).'" henüz yazılmadı.']);
        }
        if ($article->status === 'needs_fix' || $article->blockingViolations() !== []) {
            throw ValidationException::withMessages(['write' => '"'.mb_substr($article->title, 0, 60).'" sektör uyum kurallarına takılıyor; önce metni düzenle ya da yeniden yazdır.']);
        }
        if (! in_array($article->status, ContentArticle::DELIVERABLE, true)) {
            throw ValidationException::withMessages(['write' => '"'.mb_substr($article->title, 0, 60).'" gönderilemez ('.$article->statusLabel().').']);
        }
    }
}
