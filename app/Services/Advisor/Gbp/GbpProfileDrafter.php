<?php

namespace App\Services\Advisor\Gbp;

use App\Ai\Agents\GbpProfileAgent;
use App\Models\AdvisorItem;
use App\Models\DigitalAsset;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One AI call, on operator click, for a "profile gaps" item: description and service texts from stored
 * profile, brand and search keyword data. Over-long lines and texts with links/phones are dropped.
 */
final class GbpProfileDrafter
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly GbpAdvisorInputCollector $collector,
    ) {}

    public function draft(AdvisorItem $item): AdvisorItem
    {
        try {
            $route = $this->routes->resolve(AiRouteKeys::GBP_PROFILE_DRAFT);
            if ($route->isEmpty()) {
                return $this->fail($item, 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $response = (new GbpProfileAgent)->prompt(
                "CONTEXT_JSON\n".json_encode($this->context($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 90,
            );
            $draft = $this->validate((array) $response->toArray());
        } catch (Throwable $exception) {
            return $this->fail($item, 'AI taslağı hazırlanamadı: '.mb_substr($exception->getMessage(), 0, 200));
        }
        if ($draft['profile_descriptions'] === []) {
            return $this->fail($item, 'AI geçerli açıklama döndürmedi; tekrar dene.');
        }
        $item->forceFill(['draft' => $draft + ['prompt_version' => GbpProfileAgent::PROMPT_VERSION, 'provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'created_at' => now()->toIso8601String()], 'draft_status' => 'ready'])->save();

        return $item;
    }

    /** Google limits: description ≤ 750 characters, no links or phone numbers. */
    public function validate(array $raw): array
    {
        $clean = static fn (mixed $text): string => trim(preg_replace('/[ \t]+/u', ' ', is_string($text) ? $text : '') ?? '');
        $forbidden = static fn (string $text): bool => preg_match('~https?://|www\.|\d{3}[\s-]?\d{3}[\s-]?\d{2,4}~iu', $text) === 1;
        $descriptions = [];
        foreach ((array) ($raw['profile_descriptions'] ?? []) as $text) {
            $text = $clean($text);
            if ($text !== '' && mb_strlen($text) <= 750 && ! $forbidden($text)) {
                $descriptions[] = $text;
            }
        }
        $services = [];
        foreach ((array) ($raw['service_descriptions'] ?? []) as $text) {
            $text = $clean($text);
            if ($text !== '' && mb_strlen($text) <= 340 && ! $forbidden($text)) {
                $services[] = $text;
            }
        }

        return [
            'profile_descriptions' => array_slice($descriptions, 0, 2),
            'service_descriptions' => array_slice($services, 0, 8),
            'notes' => mb_substr($clean($raw['notes'] ?? ''), 0, 400),
        ];
    }

    /** @return array<string, mixed> */
    private function context(AdvisorItem $item): array
    {
        $asset = DigitalAsset::query()->with('brand')->findOrFail($item->digital_asset_id);
        $input = $this->collector->collect($asset);
        $home = null;
        foreach ($input['website']['pages'] ?? [] as $page) {
            if (in_array(parse_url((string) $page['url'], PHP_URL_PATH) ?: '/', ['/', ''], true)) {
                $home = $page;
                break;
            }
        }

        return [
            'business' => $input['location']['title'] ?? $asset->brand?->name,
            'primary_category' => $input['location']['primary_category'] ?? null,
            'additional_categories' => $input['location']['additional_categories'] ?? [],
            'services' => array_map(static fn (array $o): string => $o['name'], $input['offerings'] ?? []),
            'service_areas' => $input['service_areas'] ?? [],
            'current_description' => $input['location']['description'] ?? null,
            'top_searches' => array_slice(array_column($input['keywords']['items'] ?? [], 'keyword'), 0, 20),
            'website_home' => ['title' => $home['title'] ?? null, 'meta_description' => $home['meta_description'] ?? null],
        ];
    }

    private function fail(AdvisorItem $item, string $message): AdvisorItem
    {
        DB::table('advisor_items')->where('id', $item->id)->update(['draft_status' => 'failed', 'draft' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return $item->refresh();
    }
}
