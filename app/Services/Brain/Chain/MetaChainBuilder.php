<?php

namespace App\Services\Brain\Chain;

use App\Models\DigitalAsset;
use App\Models\ServiceMatchingKeyword;
use App\Services\Advisor\MetaAds\MetaAdsAdvisorInputCollector;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Meta side of the service chain: every ad of the account with its spend, results and format, filed under the
 * service it promotes. A single hit of a service's names / matching expressions in the ad, ad set or campaign name or
 * the creative text files it directly (rule); the rest are left without a service for "AI ile hazırla" to propose,
 * and an operator-approved choice is never overwritten by a later rule run.
 */
final class MetaChainBuilder
{
    public function __construct(
        private readonly MetaAdsAdvisorInputCollector $collector,
        private readonly ServiceKeywordService $keywords,
    ) {}

    /** @return int ads stored */
    public function build(DigitalAsset $asset): int
    {
        try {
            $data = $this->collector->collect($asset);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
        if (! ($data['bound'] ?? false)) {
            return 0;
        }

        return $this->store($asset, $data);
    }

    /** @param  array<string, mixed>  $data  MetaAdsAdvisorInputCollector output */
    public function store(DigitalAsset $asset, array $data): int
    {
        $services = $this->services((int) $asset->brand_id);
        $existing = DB::table('brain_meta_ads')->where('digital_asset_id', $asset->id)->get()->keyBy('ad_id');
        $window = (string) ($data['period']['start'] ?? now()->subDays(30)->toDateString());
        $count = 0;
        foreach ((array) ($data['ads'] ?? []) as $adId => $ad) {
            $creative = (array) (($data['creatives'] ?? [])[$ad['creative_id'] ?? ''] ?? []);
            $text = implode(' ', array_filter([
                $ad['name'] ?? null,
                $data['adsets'][$ad['adset_id'] ?? '']['name'] ?? null,
                $data['campaigns'][$ad['campaign_id'] ?? '']['name'] ?? null,
                $creative['title'] ?? null, $creative['body'] ?? null, $creative['name'] ?? null,
            ]));
            $metrics = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'results' => 0.0];
            foreach ((array) ($ad['daily'] ?? []) as $date => $day) {
                if ($date >= $window) {
                    $metrics['spend'] += (float) ($day['spend'] ?? 0);
                    $metrics['impressions'] += (int) ($day['impressions'] ?? 0);
                    $metrics['clicks'] += (int) ($day['clicks'] ?? 0);
                    $metrics['results'] += (float) ($day['results'] ?? 0);
                }
            }
            $previous = $existing->get((string) $adId);
            $decided = $previous !== null && in_array($previous->source, ['ai', 'operator'], true);
            $hits = $decided ? [] : $this->match($text, $services);
            $values = [
                'brand_id' => $asset->brand_id, 'ad_name' => mb_substr((string) ($ad['name'] ?? ''), 0, 500), 'creative_id' => $ad['creative_id'] ?? null,
                'format' => $this->format($creative), 'spend' => round($metrics['spend'], 2), 'impressions' => $metrics['impressions'],
                'clicks' => $metrics['clicks'], 'results' => round($metrics['results'], 2), 'computed_at' => now(), 'updated_at' => now(),
            ];
            if (! $decided) {
                $values += count($hits) === 1 ? ['service_id' => $hits[0], 'source' => 'rule', 'confidence' => 0.9] : ['service_id' => null, 'source' => null, 'confidence' => null];
            }
            DB::table('brain_meta_ads')->updateOrInsert(['digital_asset_id' => $asset->id, 'ad_id' => (string) $adId], $values + ['created_at' => $previous->created_at ?? now()]);
            $count++;
        }

        return $count;
    }

    /**
     * Services the brand offers: id => [folded names, matching expressions].
     *
     * @return array<int, array{names: list<string>, words: Collection<int, ServiceMatchingKeyword>}>
     */
    public function services(int $brandId): array
    {
        $ids = DB::table('brand_offerings')->where('brand_id', $brandId)->where('status', 'active')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id')->map('intval')->all();
        $names = DB::table('service_catalog_names')->whereIn('service_catalog_item_id', $ids)->where('is_active', true)->get(['service_catalog_item_id', 'raw_label'])->groupBy('service_catalog_item_id');
        $words = ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $ids)->get()->groupBy('service_catalog_item_id');
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'names' => collect($names->get($id, []))->map(fn ($n): string => SeoText::fold((string) $n->raw_label))->filter(fn (string $n): bool => mb_strlen($n) >= 4)->values()->all(),
                'words' => $words->get($id, collect()),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{names: list<string>, words: Collection<int, ServiceMatchingKeyword>}>  $services
     * @return list<int>
     */
    private function match(string $text, array $services): array
    {
        if (trim($text) === '') {
            return [];
        }
        $hits = [];
        foreach ($services as $id => $service) {
            $byName = collect($service['names'])->contains(fn (string $name): bool => SeoText::matchesPhrase($text, $name));
            if ($byName || $this->keywords->matches($text, [$id], $service['words']) !== []) {
                $hits[] = $id;
            }
        }

        return $hits;
    }

    /** @param  array<string, mixed>  $creative */
    private function format(array $creative): ?string
    {
        $type = strtoupper((string) ($creative['object_type'] ?? ''));

        return match (true) {
            str_contains($type, 'VIDEO') => 'video',
            str_contains($type, 'CAROUSEL') || str_contains($type, 'MULTI') => 'carousel',
            $type === '' => null,
            default => 'image',
        };
    }
}
