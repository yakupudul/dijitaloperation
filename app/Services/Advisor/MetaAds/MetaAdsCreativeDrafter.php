<?php

namespace App\Services\Advisor\MetaAds;

use App\Ai\Agents\MetaAdsCreativeAgent;
use App\Models\AdvisorItem;
use App\Models\DigitalAsset;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One AI call, on operator click, for one "creative fatigue" item: new creative ideas and copy variants
 * from stored data only, length-checked. The operator builds the new ads in Meta.
 */
final class MetaAdsCreativeDrafter
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly SeoPlanInputCollector $seoInputs,
        private readonly AdvisorWebsiteReader $websiteReader,
    ) {}

    public function draft(AdvisorItem $item): AdvisorItem
    {
        try {
            $route = $this->routes->resolve(AiRouteKeys::META_ADS_CREATIVE_DRAFT);
            if ($route->isEmpty()) {
                return $this->fail($item, 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $response = (new MetaAdsCreativeAgent)->prompt(
                "CONTEXT_JSON\n".json_encode($this->context($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 90,
            );
            $draft = $this->validate((array) $response->toArray());
        } catch (Throwable $exception) {
            return $this->fail($item, 'AI taslağı hazırlanamadı: '.mb_substr($exception->getMessage(), 0, 200));
        }
        if ($draft['primary_texts'] === [] || $draft['headlines'] === []) {
            return $this->fail($item, 'AI geçerli metin döndürmedi; tekrar dene.');
        }
        $item->forceFill(['draft' => $draft + ['prompt_version' => MetaAdsCreativeAgent::PROMPT_VERSION, 'provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'created_at' => now()->toIso8601String()], 'draft_status' => 'ready'])->save();

        return $item;
    }

    /** Lines over the limit are dropped, not cut. */
    public function validate(array $raw): array
    {
        $clean = static fn (mixed $text): string => trim(preg_replace('/[ \t]+/u', ' ', is_string($text) ? $text : '') ?? '');
        $list = static function (mixed $values, int $max, int $count) use ($clean): array {
            $out = [];
            foreach ((array) $values as $value) {
                $value = $clean($value);
                if ($value !== '' && mb_strlen($value) <= $max && ! in_array($value, $out, true)) {
                    $out[] = $value;
                }
            }

            return array_slice($out, 0, $count);
        };

        return [
            'concepts' => $list($raw['concepts'] ?? [], 300, 3),
            'primary_texts' => $list($raw['primary_texts'] ?? [], 250, 3),
            'headlines' => $list($raw['headlines'] ?? [], 40, 5),
            'descriptions' => $list($raw['descriptions'] ?? [], 30, 3),
            'notes' => mb_substr($clean($raw['notes'] ?? ''), 0, 400),
        ];
    }

    /** @return array<string, mixed> */
    private function context(AdvisorItem $item): array
    {
        $asset = DigitalAsset::query()->with('brand')->findOrFail($item->digital_asset_id);
        $evidence = $item->evidence ?? [];
        $url = (string) ($evidence['final_url'] ?? '');
        $page = $url !== '' ? ($this->websiteReader->forBrandOf($asset)['pages'][SeoText::urlKey($url)] ?? null) : null;

        return [
            'brand' => $asset->brand?->name,
            'services' => array_map(static fn (array $o): string => $o['name'], $asset->brand_id !== null ? $this->seoInputs->offerings($asset) : []),
            'campaign' => $evidence['campaign'] ?? null,
            'objective' => $evidence['objective'] ?? null,
            'current_ad' => ['name' => $evidence['ad'] ?? null, 'title' => $evidence['creative_title'] ?? null, 'body' => $evidence['creative_body'] ?? null, 'call_to_action' => $evidence['creative_cta'] ?? null],
            'performance' => $evidence['weeks'] ?? [],
            // Faz 6 (Hizmet Beyni): the brand's sector rules go INTO the prompt, so the draft is compliant from the start;
            // the same rules still check the finished draft afterwards.
            'compliance_rules' => $asset->brand !== null ? app(SectorPackRegistry::class)->rulesForBrand($asset->brand)
                ->filter(fn ($rule): bool => $rule->appliesTo('meta_ad'))->map(fn ($rule): string => $rule->label.': '.$rule->message.' (yasak ifadeler: '.implode(', ', array_slice((array) $rule->patterns, 0, 12)).')')->values()->take(12)->all() : [],
            'landing_page' => ['url' => $url ?: null, 'title' => $page['title'] ?? null, 'h1' => $page['h1'] ?? null, 'meta_description' => $page['meta_description'] ?? null],
        ];
    }

    private function fail(AdvisorItem $item, string $message): AdvisorItem
    {
        DB::table('advisor_items')->where('id', $item->id)->update(['draft_status' => 'failed', 'draft' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return $item->refresh();
    }
}
