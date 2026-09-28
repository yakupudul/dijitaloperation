<?php

namespace App\Services\ContentDelivery;

use App\Ai\Agents\Content\ContentLocalizerAgent;
use App\Models\DigitalAsset;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\SiteFixes\SiteFixAi;
use App\Support\Ai\AiRouteKeys;
use App\Support\ServiceScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ADR-076: AI localization of an article into another language of the site (route `content.localize`, AI budget and
 * provider failover like every route). Internal links are rewritten to the target-language page when the site knows it
 * (Polylang translations / hreflang), otherwise to the language home. The result passes the compliance gate; when it
 * does not, the model is asked once more with the violations. Nothing is sent to WordPress here.
 *
 * Calls the AI synchronously: run it from a queued job (OPERATOR_ASYNC_EXECUTION), not from a request.
 */
final class ContentLocalizer
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ContentComplianceGate $gate,
        private readonly LanguageLinkMap $links,
    ) {}

    /**
     * @param  Model|null  $archiveSubject  when given, the output is kept in the AI production archive (`content.localized`)
     * @return array{article: ArticleDraft, violations: list<array<string, mixed>>, attempts: int}
     */
    public function localize(ArticleDraft $source, string $targetLanguage, DigitalAsset $site, int $maxAttempts = 2, ?Model $archiveSubject = null): array
    {
        $targetLanguage = strtolower(trim($targetLanguage));
        if (preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/', $targetLanguage) !== 1) {
            throw new RuntimeException('Geçersiz hedef dil: '.$targetLanguage);
        }
        if ($source->language !== null && $source->language === $targetLanguage) {
            throw new RuntimeException('Kaynak ve hedef dil aynı.');
        }
        if (! app(ServiceScope::class)->isAssetOperational($site->id)) {
            throw ServiceScope::notServed();
        }
        $route = $this->routes->resolve(AiRouteKeys::CONTENT_LOCALIZE);
        if ($route->isEmpty()) {
            throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).');
        }
        $this->runtime->prepare(array_keys($route->providerModels));

        $brand = $site->brand;
        $linkMap = $this->links->map($site, $targetLanguage);
        $home = $this->links->home($site, $targetLanguage);
        $input = [
            'source_language' => $source->language ?? 'tr',
            'target_language' => $targetLanguage,
            'business' => app(SiteFixAi::class)->businessFacts($site),
            'compliance' => $brand !== null ? app(SectorPackRegistry::class)->rulesForBrand($brand)->map(fn ($r): array => ['rule' => $r->label, 'avoid' => array_slice((array) $r->patterns, 0, 40), 'instruction' => $r->message])->values()->all() : [],
            'link_map' => array_slice($linkMap, 0, 200, true),
            'language_home' => $home,
            'source' => [
                'title' => $source->title, 'slug' => $source->slug, 'meta_title' => $source->metaTitle, 'meta_description' => $source->metaDescription,
                'focus_keyword' => $source->focusKeyword, 'excerpt' => $source->excerpt, 'html' => mb_substr($source->html, 0, 60000),
                'categories' => array_column($source->categories, 'name'),
            ],
        ];

        $article = null;
        $violations = [];
        $attempts = 0;
        while ($attempts < max(1, $maxAttempts)) {
            $attempts++;
            if ($violations !== []) {
                $input['violations'] = ContentComplianceGate::forPrompt($violations);
            }
            $response = (array) (new ContentLocalizerAgent)->prompt("INPUT_JSON\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 240)->toArray();
            $article = $this->article($source, $targetLanguage, $response, $linkMap, $home, $this->links->hosts($site));
            $violations = $this->gate->violations($brand, $article);
            if (ContentComplianceGate::blocking($violations) === []) {
                break;
            }
        }
        if ($archiveSubject !== null) {
            app(ProductionArchive::class)->record('content.localized', $archiveSubject, $article->toArray() + ['violations' => $violations, 'provider' => $route->primaryModel(), 'prompt_version' => ContentLocalizerAgent::PROMPT_VERSION],
                ['brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'title' => 'Yerelleştirme ('.$targetLanguage.') · '.$source->title]);
        }

        return ['article' => $article, 'violations' => $violations, 'attempts' => $attempts];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $linkMap
     * @param  list<string>  $hosts
     */
    private function article(ArticleDraft $source, string $language, array $response, array $linkMap, ?string $home, array $hosts): ArticleDraft
    {
        $html = SiteFixAi::cleanHtml(trim((string) ($response['html'] ?? '')));
        if (trim(strip_tags($html)) === '') {
            throw new RuntimeException('AI boş metin döndürdü.');
        }
        $html = LanguageLinkMap::rewrite($html, $linkMap, $home, $hosts);
        $names = array_values(array_map('strval', (array) ($response['categories'] ?? [])));
        $categories = [];
        foreach ($source->categories as $i => $category) {
            $name = trim($names[$i] ?? '') !== '' ? trim($names[$i]) : $category['name'];
            $categories[] = ['name' => $name, 'source' => $category['name']];
        }
        $title = trim(strip_tags((string) ($response['title'] ?? '')));

        return $source->with([
            'title' => $title !== '' ? mb_substr($title, 0, 200) : $source->title,
            'html' => $html,
            'slug' => Str::slug((string) ($response['slug'] ?? '') ?: $title, '-', $language),
            'meta_title' => mb_substr(trim(strip_tags((string) ($response['meta_title'] ?? ''))), 0, 70),
            'meta_description' => mb_substr(trim(strip_tags((string) ($response['meta_description'] ?? ''))), 0, 170),
            'focus_keyword' => mb_substr(trim(strip_tags((string) ($response['focus_keyword'] ?? ''))), 0, 100),
            'excerpt' => mb_substr(trim(strip_tags((string) ($response['excerpt'] ?? ''))), 0, 500),
            'categories' => $categories,
            'tags' => [],
            'language' => $language,
            'reference' => $source->reference.'-'.$language,
            'translation_key' => $source->translationKey ?? $source->reference,
        ]);
    }
}
