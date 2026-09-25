<?php

namespace App\Services\Advisor\GoogleAds;

use App\Ai\Agents\GoogleAdsAdCopyAgent;
use App\Models\AdvisorItem;
use App\Models\DigitalAsset;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One AI call, on operator click, for one "weak ad strength" item: context from stored data only,
 * output length-checked against Google's limits. The operator copies it into Google Ads.
 */
final class GoogleAdsAdCopyDrafter
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly SeoPlanInputCollector $seoInputs,
        private readonly GoogleAdsAdvisorInputCollector $collector,
    ) {}

    public function draft(AdvisorItem $item): AdvisorItem
    {
        try {
            $route = $this->routes->resolve(AiRouteKeys::GOOGLE_ADS_AD_COPY_DRAFT);
            if ($route->isEmpty()) {
                return $this->fail($item, 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).');
            }
            $context = $this->context($item);
            $this->runtime->prepare(array_keys($route->providerModels));
            $response = (new GoogleAdsAdCopyAgent)->prompt(
                "CONTEXT_JSON\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 90,
            );
            $draft = $this->validate((array) $response->toArray());
        } catch (Throwable $exception) {
            return $this->fail($item, 'AI taslağı hazırlanamadı: '.mb_substr($exception->getMessage(), 0, 200));
        }
        if ($draft['headlines'] === [] || $draft['descriptions'] === []) {
            return $this->fail($item, 'AI geçerli başlık/açıklama döndürmedi; tekrar dene.');
        }
        $item->forceFill(['draft' => $draft + ['prompt_version' => GoogleAdsAdCopyAgent::PROMPT_VERSION, 'provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'created_at' => now()->toIso8601String()], 'draft_status' => 'ready'])->save();

        return $item;
    }

    /** Google limits: headline ≤ 30, description ≤ 90, path ≤ 15. Over-long lines are dropped, not cut. */
    public function validate(array $raw): array
    {
        $clean = static fn (mixed $text): string => trim(preg_replace('/\s+/u', ' ', is_string($text) ? $text : '') ?? '');
        $headlines = [];
        foreach ((array) ($raw['headlines'] ?? []) as $headline) {
            $headline = $clean($headline);
            if ($headline !== '' && mb_strlen($headline) <= 30 && ! isset($headlines[mb_strtolower($headline)])) {
                $headlines[mb_strtolower($headline)] = $headline;
            }
        }
        $descriptions = [];
        foreach ((array) ($raw['descriptions'] ?? []) as $description) {
            $description = $clean($description);
            if ($description !== '' && mb_strlen($description) <= 90) {
                $descriptions[] = $description;
            }
        }
        $path = static fn (mixed $p): string => mb_substr(SeoText::slugify($clean($p)), 0, 15);

        return [
            'headlines' => array_slice(array_values($headlines), 0, 15),
            'descriptions' => array_slice(array_values(array_unique($descriptions)), 0, 4),
            'path1' => $path($raw['path1'] ?? ''),
            'path2' => $path($raw['path2'] ?? ''),
            'notes' => mb_substr($clean($raw['notes'] ?? ''), 0, 400),
        ];
    }

    /** @return array<string, mixed> */
    private function context(AdvisorItem $item): array
    {
        $asset = DigitalAsset::query()->with('brand')->findOrFail($item->digital_asset_id);
        $adGroupId = (string) ($item->evidence['ad_group_id'] ?? '');
        $input = $this->collector->collect($asset);
        $keywords = array_values(array_filter($input['keywords'] ?? [], static fn (array $k): bool => $k['ad_group_id'] === $adGroupId && strtoupper((string) $k['status']) !== 'REMOVED'));
        usort($keywords, static fn (array $a, array $b): int => [$b['conversions'], $b['clicks']] <=> [$a['conversions'], $a['clicks']]);
        $terms = array_values(array_filter($input['search_terms'] ?? [], static fn (array $t): bool => in_array($adGroupId, $t['ad_group_ids'], true) && $t['clicks'] > 0));
        usort($terms, static fn (array $a, array $b): int => [$b['conversions'], $b['clicks']] <=> [$a['conversions'], $a['clicks']]);
        $finalUrl = (string) ($item->evidence['final_url'] ?? '');
        $page = $finalUrl !== '' ? ($input['website']['pages'][SeoText::urlKey($finalUrl)] ?? null) : null;

        return [
            'brand' => $asset->brand?->name,
            'services' => array_map(static fn (array $o): string => $o['name'], $asset->brand_id !== null ? $this->seoInputs->offerings($asset) : []),
            'campaign' => $item->evidence['campaign'] ?? null,
            'ad_group' => $item->evidence['ad_group'] ?? null,
            'keywords' => array_map(static fn (array $k): array => ['text' => $k['text'], 'match_type' => $k['match_type'], 'clicks' => $k['clicks'], 'conversions' => $k['conversions']], array_slice($keywords, 0, 20)),
            'search_terms' => array_map(static fn (array $t): array => ['term' => $t['term'], 'clicks' => $t['clicks'], 'conversions' => $t['conversions']], array_slice($terms, 0, 25)),
            // Faz 6 (Hizmet Beyni): the brand's sector rules go INTO the prompt, so the draft is compliant from the start;
            // the same rules still check the finished draft afterwards.
            'compliance_rules' => $asset->brand !== null ? app(SectorPackRegistry::class)->rulesForBrand($asset->brand)
                ->filter(fn ($rule): bool => $rule->appliesTo('google_ads_ad'))->map(fn ($rule): string => $rule->label.': '.$rule->message.' (yasak ifadeler: '.implode(', ', array_slice((array) $rule->patterns, 0, 12)).')')->values()->take(12)->all() : [],
            'landing_page' => ['url' => $finalUrl ?: null, 'title' => $page['title'] ?? null, 'h1' => $page['h1'] ?? null, 'meta_description' => $page['meta_description'] ?? null],
        ];
    }

    private function fail(AdvisorItem $item, string $message): AdvisorItem
    {
        DB::table('advisor_items')->where('id', $item->id)->update(['draft_status' => 'failed', 'draft' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return $item->refresh();
    }
}
