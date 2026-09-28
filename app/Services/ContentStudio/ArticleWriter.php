<?php

namespace App\Services\ContentStudio;

use App\Ai\Agents\Content\ArticleWriterAgent;
use App\Models\Brand;
use App\Models\ContentArticle;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ContentDelivery\ArticleDraft;
use App\Services\ContentDelivery\ContentComplianceGate;
use App\Services\ContentDelivery\ContentLocalizer;
use App\Services\ContentDelivery\LanguageLinkMap;
use App\Services\SeoTasks\SeoText;
use App\Services\SiteFixes\SiteFixAi;
use App\Support\Ai\AiRouteKeys;
use App\Support\ServiceScope;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Faz 4 — writes one studio article with ArticleWriterAgent (route `content.article`, AI budget and provider failover
 * like every route) and localizes it (ContentLocalizer, route `content.localize`). Every result passes the sector
 * compliance gate: on violations the model is asked once more with the offending phrases; if it still breaks a rule the
 * article is kept as "uyum sorunu var" and needs an operator edit. Internal links are limited to the real inventory
 * URLs of the idea; a YMYL sector's closing note is always present. Outputs are archived (`content.article`).
 *
 * Calls the AI synchronously: run it from a queued job (OPERATOR_ASYNC_EXECUTION), not from a request.
 */
final class ArticleWriter
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ContentComplianceGate $gate,
        private readonly ProductionArchive $archive,
        private readonly LanguageLinkMap $links,
    ) {}

    public function write(ContentArticle $article, int $maxAttempts = 2): ContentArticle
    {
        $site = DigitalAsset::query()->where('type', 'website')->findOrFail($article->digital_asset_id);
        if (! app(ServiceScope::class)->isAssetOperational($site->id)) {
            throw ServiceScope::notServed();
        }
        $idea = ContentIdea::query()->findOrFail($article->content_idea_id);
        $route = $this->routes->resolve(AiRouteKeys::CONTENT_ARTICLE);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).');
        }
        $this->runtime->prepare(array_keys($route->providerModels));

        $brand = $site->brand;
        $inventory = SiteContentInventory::for($site);
        $allowed = array_values(array_filter((array) $idea->internal_links, fn ($link): bool => is_array($link) && filled($link['url'] ?? null) && $inventory->byUrl((string) $link['url']) !== null));
        $disclaimer = self::disclaimer($brand);
        $serviceName = $idea->offering?->displayName();
        $input = [
            'language' => $article->language ?? 'tr',
            'business' => app(SiteFixAi::class)->businessFacts($site),
            'idea' => [
                'title' => $idea->title, 'focus_keyword' => $idea->focus_keyword, 'queries' => array_slice((array) $idea->queries, 0, 10),
                'page_type' => $idea->page_type, 'service' => $serviceName, 'location' => $idea->location,
                'outline' => (array) $idea->outline, 'faq' => (array) $idea->faq,
            ],
            'words' => ['min' => (int) config('moxdop-content.article.min_words', 850), 'max' => (int) config('moxdop-content.article.max_words', 1100)],
            'internal_links' => array_map(fn (array $l): array => ['url' => (string) $l['url'], 'title' => (string) ($l['title'] ?? '')], $allowed),
            'site_categories' => array_slice(array_column($inventory->categories(), 'name'), 0, 40),
            'compliance' => BriefCompliance::forBrand($brand)->forPrompt(),
            'disclaimer' => $disclaimer,
        ];

        $draft = null;
        $violations = [];
        $attempts = 0;
        while ($attempts < max(1, $maxAttempts)) {
            $attempts++;
            if ($violations !== []) {
                $input['violations'] = ContentComplianceGate::forPrompt($violations);
            }
            $response = (array) (new ArticleWriterAgent)->prompt("INPUT_JSON\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 300)->toArray();
            $draft = $this->draft($article, $idea, $response, $allowed, $site, $inventory, $disclaimer, $serviceName);
            $violations = $this->gate->violations($brand, $draft);
            if (ContentComplianceGate::blocking($violations) === []) {
                break;
            }
        }

        $quality = self::quality($draft, $idea, $allowed);
        $production = $this->archive->record('content.article', $article, $draft->toArray() + ['violations' => $violations, 'provider' => $route->primaryModel(), 'prompt_version' => ArticleWriterAgent::PROMPT_VERSION],
            ['brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'title' => 'Makale · '.$draft->title, 'provider' => $route->primaryProvider(), 'model' => $route->primaryModel()]);
        $article->forceFill([
            'title' => mb_substr($draft->title, 0, 250),
            'payload' => self::payload($draft),
            'compliance' => $violations,
            'quality' => $quality,
            'word_count' => $quality['words'],
            'attempts' => (int) $article->attempts + $attempts,
            'status' => ContentComplianceGate::blocking($violations) === [] ? 'ready' : 'needs_fix',
            'ai_production_id' => $production?->id ?? $article->ai_production_id,
            'error' => null,
        ])->save();
        $idea->forceFill(['status' => 'written'])->save();

        return $article;
    }

    /** Localized version of a written source article, into the translation row's language. */
    public function localize(ContentArticle $translation): ContentArticle
    {
        $source = ContentArticle::query()->findOrFail($translation->source_article_id);
        if (! $source->hasDraft()) {
            throw new RuntimeException('Kaynak makale henüz yazılmadı.');
        }
        $site = DigitalAsset::query()->where('type', 'website')->findOrFail($translation->digital_asset_id);
        $result = app(ContentLocalizer::class)->localize($source->draft(), (string) $translation->language, $site, 2, $translation);
        $article = $result['article'];
        $blocking = ContentComplianceGate::blocking($result['violations']) !== [];
        $translation->forceFill([
            'title' => mb_substr($article->title, 0, 250),
            'payload' => self::payload($article),
            'compliance' => $result['violations'],
            'quality' => ['words' => self::words($article->html), 'issues' => []],
            'word_count' => self::words($article->html),
            'attempts' => (int) $translation->attempts + $result['attempts'],
            'status' => $blocking ? 'needs_fix' : 'ready',
            'error' => null,
        ])->save();

        return $translation;
    }

    /**
     * Re-check after an operator edit (title, SEO fields, HTML): payload and compliance are updated.
     *
     * @param  array{title?: string, meta_title?: string, meta_description?: string, html?: string, focus_keyword?: string, excerpt?: string}  $changes
     */
    public function edit(ContentArticle $article, array $changes): ContentArticle
    {
        $payload = (array) $article->payload;
        foreach (['title' => 250, 'meta_title' => 70, 'meta_description' => 170, 'focus_keyword' => 200, 'excerpt' => 500] as $field => $max) {
            if (array_key_exists($field, $changes)) {
                $payload[$field] = mb_substr(trim(strip_tags((string) $changes[$field])), 0, $max);
            }
        }
        if (array_key_exists('html', $changes)) {
            $payload['html'] = SiteFixAi::cleanHtml((string) $changes['html']);
        }
        if (trim((string) ($payload['title'] ?? '')) === '' || trim(strip_tags((string) ($payload['html'] ?? ''))) === '') {
            throw new RuntimeException('Başlık ve metin boş olamaz.');
        }
        $article->payload = $payload;
        $site = DigitalAsset::query()->findOrFail($article->digital_asset_id);
        $violations = $this->gate->violations($site->brand, $article->draft());
        $article->forceFill([
            'title' => mb_substr((string) $payload['title'], 0, 250),
            'compliance' => $violations,
            'word_count' => self::words((string) $payload['html']),
            'status' => in_array($article->status, ['ready', 'needs_fix', 'failed'], true) ? (ContentComplianceGate::blocking($violations) === [] ? 'ready' : 'needs_fix') : $article->status,
        ])->save();

        return $article;
    }

    /** The sector's closing informational note (YMYL), or null. */
    public static function disclaimer(?Brand $brand): ?string
    {
        if ($brand === null) {
            return null;
        }
        $notes = (array) config('moxdop-content.disclaimers', []);
        foreach (app(SectorPackRegistry::class)->forBrand($brand) as $pack) {
            if (filled($notes[$pack->id()] ?? null)) {
                return (string) $notes[$pack->id()];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<array<string, mixed>>  $allowed
     */
    private function draft(ContentArticle $article, ContentIdea $idea, array $response, array $allowed, DigitalAsset $site, SiteContentInventory $inventory, ?string $disclaimer, ?string $serviceName): ArticleDraft
    {
        $html = SiteFixAi::cleanHtml(trim((string) ($response['html'] ?? '')));
        if (trim(strip_tags($html)) === '') {
            throw new RuntimeException('AI boş metin döndürdü.');
        }
        $html = self::limitLinks($html, array_column($allowed, 'url'), $this->links->hosts($site));
        if ($disclaimer !== null && ! str_contains(SeoText::fold(strip_tags($html)), mb_substr(SeoText::fold($disclaimer), 0, 40))) {
            $html .= '<p><em>'.e($disclaimer).'</em></p>';
        }
        $title = mb_substr(trim(strip_tags((string) ($response['title'] ?? ''))) ?: $idea->title, 0, 200);
        $metaTitle = self::cut(trim(strip_tags((string) ($response['meta_title'] ?? ''))) ?: $title, (int) config('moxdop-content.article.meta_title_max', 60));
        $metaDescription = self::cut(trim(strip_tags((string) ($response['meta_description'] ?? ''))), (int) config('moxdop-content.article.meta_description_max', 155));
        $pageType = in_array($idea->page_type, ['service', 'location'], true) ? 'page' : 'post';
        $categories = [];
        foreach (array_slice(array_map('strval', (array) ($response['categories'] ?? [])), 0, 2) as $name) {
            $name = trim(strip_tags($name));
            if ($name !== '') {
                $categories[SeoText::fold($inventory->category($name) ?? $name)] = ['name' => $inventory->category($name) ?? $name];
            }
        }
        if ($categories === [] && $serviceName !== null) {
            $categories[] = ['name' => $inventory->category($serviceName) ?? $serviceName];
        }

        return new ArticleDraft(
            title: $title,
            html: $html,
            reference: $article->reference(),
            slug: Str::slug((string) ($response['slug'] ?? '') ?: $title, '-', 'tr'),
            excerpt: mb_substr(trim(strip_tags((string) ($response['excerpt'] ?? ''))), 0, 500),
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            focusKeyword: mb_substr(trim(strip_tags((string) ($response['focus_keyword'] ?? ''))) ?: (string) $idea->focus_keyword, 0, 100),
            categories: $pageType === 'post' ? array_values($categories) : [],
            language: $article->language,
            postType: $pageType,
            translationKey: $article->translation_key,
        );
    }

    /**
     * Links to the site's own hosts survive only when they are one of the allowed inventory URLs; others are unwrapped
     * (the anchor text stays). External links stay.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $hosts
     */
    public static function limitLinks(string $html, array $allowed, array $hosts): string
    {
        $allowedKeys = array_map(fn (string $u): string => SeoText::urlKey($u), $allowed);
        $hosts = array_map(fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? $h, $hosts);

        return preg_replace_callback('#<a href="([^"]*)">(.*?)</a>#is', static function (array $m) use ($allowedKeys, $hosts): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $internal = str_starts_with($url, '/') || ($host !== '' && in_array(preg_replace('/^www\./', '', $host), $hosts, true));
            if (! $internal) {
                return $m[0];
            }
            $absolute = str_starts_with($url, '/') && $hosts !== [] ? 'https://'.$hosts[0].$url : $url;

            return in_array(SeoText::urlKey($absolute), $allowedKeys, true) ? $m[0] : $m[2];
        }, $html) ?? $html;
    }

    /**
     * Checks the operator sees next to the article (not blocking): length, structure, FAQ, links, SEO field lengths.
     *
     * @param  list<array<string, mixed>>  $allowed
     * @return array{words: int, h2: int, lists: int, faq: bool, internal_links: int, focus_in_intro: bool, issues: list<string>}
     */
    public static function quality(ArticleDraft $draft, ContentIdea $idea, array $allowed): array
    {
        $words = self::words($draft->html);
        $h2 = preg_match_all('/<h2>/i', $draft->html);
        $lists = preg_match_all('/<(ul|ol)>/i', $draft->html);
        $faq = str_contains(SeoText::fold(strip_tags($draft->html)), 'sik sorulan') || str_contains(mb_strtolower(strip_tags($draft->html)), 'frequently asked');
        $links = 0;
        foreach ($allowed as $link) {
            if (str_contains($draft->html, 'href="'.htmlspecialchars((string) $link['url'], ENT_QUOTES).'"') || str_contains($draft->html, 'href="'.(string) $link['url'].'"')) {
                $links++;
            }
        }
        $intro = preg_match('/<p>(.*?)<\/p>/su', $draft->html, $m) === 1 ? strip_tags($m[1]) : '';
        $focusInIntro = $draft->focusKeyword !== '' && (SeoText::containsPhrase($intro, $draft->focusKeyword) || TopicText::containment($draft->focusKeyword, $intro) >= 0.8);
        $min = (int) config('moxdop-content.article.min_words', 850);
        $max = (int) config('moxdop-content.article.max_words', 1100);
        $issues = [];
        if ($words < (int) round($min * 0.9)) {
            $issues[] = sprintf('Metin kısa: %d kelime (hedef %d–%d).', $words, $min, $max);
        } elseif ($words > (int) round($max * 1.15)) {
            $issues[] = sprintf('Metin uzun: %d kelime (hedef %d–%d).', $words, $min, $max);
        }
        if ($h2 < 3) {
            $issues[] = 'H2 başlık sayısı az ('.$h2.').';
        }
        if ($lists === 0) {
            $issues[] = 'Metinde liste yok.';
        }
        if (! $faq) {
            $issues[] = 'Sık sorulan sorular bölümü yok.';
        }
        if ($links < min(2, count($allowed))) {
            $issues[] = sprintf('İç bağlantı az: %d (en az %d).', $links, min(2, count($allowed)));
        }
        if (! $focusInIntro) {
            $issues[] = 'Odak anahtar kelime giriş paragrafında geçmiyor.';
        }
        if (mb_strlen($draft->metaTitle) > 60 || $draft->metaTitle === '') {
            $issues[] = 'SEO başlığı boş ya da 60 karakterden uzun.';
        }
        if (mb_strlen($draft->metaDescription) > 155 || $draft->metaDescription === '') {
            $issues[] = 'Meta açıklama boş ya da 155 karakterden uzun.';
        }

        return ['words' => $words, 'h2' => (int) $h2, 'lists' => (int) $lists, 'faq' => $faq, 'internal_links' => $links, 'focus_in_intro' => $focusInIntro, 'issues' => $issues];
    }

    public static function words(string $html): int
    {
        $text = trim(html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /** @return array<string, mixed> the stored payload (delivery fields are filled from the row) */
    private static function payload(ArticleDraft $draft): array
    {
        $data = $draft->toArray();
        unset($data['reference'], $data['translation_key'], $data['post_date'], $data['schedule'], $data['language']);

        return $data;
    }

    /** Cut at a word boundary to at most $max characters. */
    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:-–|');
    }
}
