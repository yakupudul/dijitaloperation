<?php

namespace App\Services\Queries;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Portfolio\PortfolioDiscoveryGrouper;
use App\Services\SearchDemand\QueryExclusionService;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Builds the QueryContext of one source account. Own brand = the brand the account's asset belongs to (name +
 * website domains) plus the account's own web address and, for a Business Profile location, its business title (so
 * an unbound account's own name is never a "core" word). Competitors = the brand's competitor library and the other
 * brands of the portfolio. Product brands = the account's sector list. A mark equal to a service word ("implant") is
 * never used, so a competitor called implant.com cannot swallow every implant query.
 */
final class QueryContextFactory
{
    /** @var array<string, true>|null */
    private ?array $generic = null;

    /** @var array<int, list<string>> */
    private array $brandMarks = [];

    /** @var array<string, list<string>> */
    private array $productMarks = [];

    /** @var list<array{id: int, label: string, normalized: string}>|null */
    private ?array $rules = null;

    /** @var array<string, true>|null */
    private ?array $protected = null;

    public function __construct(
        private readonly AssetSectorService $sectors,
        private readonly PortfolioDiscoveryGrouper $grouper,
    ) {}

    public function reset(): void
    {
        $this->generic = $this->rules = $this->protected = null;
        $this->brandMarks = $this->productMarks = [];
        $this->sectors->reset();
    }

    public function forResource(CoreExternalResource $resource): QueryContext
    {
        $binding = CoreAssetBinding::query()->with('digitalAsset')->where('external_resource_id', $resource->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->first();
        $brandId = $binding?->digitalAsset?->brand_id !== null ? (int) $binding->digitalAsset->brand_id : null;
        $own = [];
        if (($host = $this->grouper->hostOf($resource)) !== null) {
            $own[] = QueryNormalizer::mark(BrandSetupMatcher::domainRoot($host));
        }
        if ($resource->resource_type === 'google_business_profile') {
            $own[] = QueryNormalizer::mark(PortfolioDiscoveryGrouper::cleanName((string) $resource->display_name, (string) $host));
        }

        return $this->build($brandId, $own, $this->sectors->sectorForResource((int) $resource->id));
    }

    public function forAsset(DigitalAsset $asset): QueryContext
    {
        $own = [QueryNormalizer::mark(BrandSetupMatcher::domainRoot(BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain))))];

        return $this->build($asset->brand_id !== null ? (int) $asset->brand_id : null, $own, $this->sectors->sectorForAsset((int) $asset->id));
    }

    /** @param  list<string>  $extraOwn */
    private function build(?int $brandId, array $extraOwn, ?string $sector): QueryContext
    {
        $generic = $this->generic();
        $own = $brandId !== null ? $this->marksOfBrand($brandId) : [];
        $own = array_values(array_unique(array_filter([...$own, ...$extraOwn], fn (string $m): bool => $m !== '' && ! isset($generic[$m]))));
        $competitors = [];
        if ($brandId !== null) {
            DB::table('search_demand_competitors')->where('brand_id', $brandId)->where('status', '!=', 'rejected')
                ->get(['display_name', 'normalized_domain'])->each(function ($row) use (&$competitors): void {
                    $name = (string) $row->display_name;
                    if (! str_contains($name, '.')) {
                        $competitors[] = QueryNormalizer::mark($name);
                    }
                    $competitors[] = QueryNormalizer::mark(BrandSetupMatcher::domainRoot(BrandSetupMatcher::host((string) $row->normalized_domain)));
                });
            foreach (Brand::query()->whereKeyNot($brandId)->pluck('id') as $otherId) {
                array_push($competitors, ...$this->marksOfBrand((int) $otherId));
            }
        }
        $competitors = array_values(array_unique(array_filter($competitors, fn (string $m): bool => $m !== '' && ! isset($generic[$m]) && ! in_array($m, $own, true))));
        sort($own);
        sort($competitors);

        return new QueryContext($own, $competitors, $this->products($sector), $this->rules(), $this->protectedHashes(), $sector);
    }

    /** @return list<string> */
    private function marksOfBrand(int $brandId): array
    {
        if (! isset($this->brandMarks[$brandId])) {
            $brand = Brand::query()->withTrashed()->find($brandId);
            $marks = [QueryNormalizer::mark((string) $brand?->name)];
            foreach (DigitalAsset::query()->where('brand_id', $brandId)->where('type', 'website')->get(['primary_url', 'domain']) as $site) {
                $marks[] = QueryNormalizer::mark(BrandSetupMatcher::domainRoot(BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain))));
            }
            $this->brandMarks[$brandId] = array_values(array_unique(array_filter($marks)));
        }

        return $this->brandMarks[$brandId];
    }

    /** @return list<string> folded product phrases of the sector */
    private function products(?string $sector): array
    {
        if ($sector === null) {
            return [];
        }

        return $this->productMarks[$sector] ??= DB::table('sector_product_brands as p')->join('service_categories as c', 'c.id', '=', 'p.service_category_id')
            ->where('c.code', $sector)->orderBy('p.normalized_key')->pluck('p.normalized_key')
            ->map(fn ($key): string => (string) $key)->filter(fn (string $key): bool => mb_strlen($key) >= 3)->unique()->values()->all();
    }

    /** Service names and matching expressions (compact) and their single words: never a brand mark. @return array<string, true> */
    private function generic(): array
    {
        if ($this->generic !== null) {
            return $this->generic;
        }
        $labels = DB::table('service_catalog_names')->where('is_active', true)->pluck('raw_label')
            ->merge(DB::table('service_matching_keywords')->pluck('normalized_key'));
        $generic = [];
        foreach ($labels as $label) {
            $fold = SeoText::fold((string) $label);
            if ($fold === '') {
                continue;
            }
            $generic[str_replace(' ', '', $fold)] = true;
            foreach (explode(' ', $fold) as $word) {
                if (strlen($word) >= 4) {
                    $generic[$word] = true;
                }
            }
        }

        return $this->generic = $generic;
    }

    /** @return list<array{id: int, label: string, normalized: string}> */
    private function rules(): array
    {
        return $this->rules ??= app(QueryExclusionService::class)->rules();
    }

    /** @return array<string, true> */
    private function protectedHashes(): array
    {
        return $this->protected ??= DB::table('query_exclusion_exceptions')->pluck('identity_hash')
            ->mapWithKeys(fn ($hash): array => [(string) $hash => true])->all();
    }
}
