<?php

namespace App\Services\Portfolio;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\SearchDemandCompetitor;
use App\Services\BrandSetup\BrandSetupMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One competitor list per brand. The competitor library (search_demand_competitors) is the single record;
 * the other places competitors were written down — the brand's free-text field, the business context's
 * known competitors and DataForSEO competitor domains — only feed suggestions that can be added with one click.
 */
final class BrandCompetitorOverview
{
    private const int SUGGESTION_LIMIT = 15;

    /**
     * @return array{competitors: list<array{id: int, name: string, domain: string, status: string, urls: int, sources: list<string>}>, suggestions: list<array{domain: string, name: string, source: string}>, name_only: list<string>}
     */
    public function forBrand(Brand $brand): array
    {
        $competitors = SearchDemandCompetitor::query()
            ->where('brand_id', $brand->id)
            ->where('status', '!=', 'rejected')
            ->withCount('urls')
            ->with('sources:id,search_demand_competitor_id,source_type')
            ->orderByRaw("case status when 'approved' then 0 else 1 end")
            ->orderBy('display_name')
            ->get();
        $known = $competitors->pluck('normalized_domain')
            ->merge(SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('status', 'rejected')->pluck('normalized_domain'))
            ->merge($this->ownDomains($brand))
            ->map(fn (string $domain): string => BrandSetupMatcher::host($domain))
            ->flip();

        $suggestions = [];
        $nameOnly = [];
        $push = function (string $value, string $name, string $source) use (&$suggestions, &$nameOnly, $known): void {
            $domain = $this->domain($value);
            if ($domain === null) {
                if (trim($name) !== '') {
                    $nameOnly[] = trim($name);
                }

                return;
            }
            if (isset($known[$domain]) || isset($suggestions[$domain])) {
                return;
            }
            $suggestions[$domain] = ['domain' => $domain, 'name' => trim($name) !== '' ? trim($name) : $domain, 'source' => $source];
        };

        foreach (preg_split('/[\n,;]+/', (string) $brand->competitors) ?: [] as $line) {
            $push($line, $this->domain($line) === null ? $line : '', 'Marka kartı');
        }
        $context = BrandIntelligenceContext::query()->where('brand_id', $brand->id)->first();
        foreach ((array) ($context?->known_competitors ?? []) as $row) {
            if (is_array($row)) {
                $push((string) ($row['url'] ?? ''), (string) ($row['name'] ?? ''), 'İşletme bilgileri');
            } elseif (is_string($row)) {
                $push($row, $row, 'İşletme bilgileri');
            }
        }
        foreach ($this->dataForSeoDomains($brand) as $domain) {
            $push($domain, '', 'DataForSEO rakip alan adları');
        }

        return [
            'competitors' => $competitors->map(fn (SearchDemandCompetitor $competitor): array => [
                'id' => (int) $competitor->id,
                'name' => (string) $competitor->display_name,
                'domain' => (string) $competitor->normalized_domain,
                'status' => (string) $competitor->status,
                'urls' => (int) $competitor->urls_count,
                'sources' => $competitor->sources->pluck('source_type')->unique()->values()->all(),
            ])->values()->all(),
            'suggestions' => array_slice(array_values($suggestions), 0, self::SUGGESTION_LIMIT),
            'name_only' => array_values(array_unique(array_filter($nameOnly, fn (string $name): bool => $this->domain($name) === null))),
        ];
    }

    /** Host of a URL or bare domain; null when the value does not look like a domain. */
    public function domain(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(\/.*)?$/i', $value)) {
            return null;
        }
        $host = BrandSetupMatcher::host($value);

        return $host !== '' ? $host : null;
    }

    /** @return list<string> */
    private function ownDomains(Brand $brand): array
    {
        return $brand->digitalAssets()->where('type', 'website')->get(['primary_url', 'domain'])
            ->map(fn ($asset): string => BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain)))
            ->filter()->values()->all();
    }

    /** @return list<string> */
    private function dataForSeoDomains(Brand $brand): array
    {
        if (! Schema::hasTable('dataforseo_competitor_domain_snapshot')) {
            return [];
        }
        $websiteIds = $brand->digitalAssets()->where('type', 'website')->pluck('id');
        if ($websiteIds->isEmpty()) {
            return [];
        }

        return DB::table('dataforseo_competitor_domain_snapshot')
            ->whereIn('digital_asset_id', $websiteIds)
            ->orderByDesc('last_collected_at')
            ->limit(200)
            ->pluck('competitor_domain')
            ->map(fn ($domain): string => (string) $domain)
            ->unique()->values()->all();
    }
}
