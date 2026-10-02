<?php

namespace App\Services\Site\Backlinks;

use App\Ai\Agents\Site\BacklinkSourcesAgent;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "AI ile kaynak öner": ONE call proposes potential link sources for the brand (sector + service areas). Each source
 * needs an https URL; already listed / already linking / own domains are skipped. A fee (ücretsiz / ücretli) is kept
 * only with an evidence URL on the source's own site, otherwise it becomes "teyit gerekli". New rows start at "yok".
 */
final class BacklinkSourceProposer
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    public static function statusKey(int $brandId): string
    {
        return 'site:backlinks:sources:'.$brandId;
    }

    public static function markRunning(int $brandId): void
    {
        Cache::put(self::statusKey($brandId), ['status' => 'running'], now()->addDay());
    }

    /** @return array{status: string, added: int} status: ready | not_operational | no_provider | error */
    public function propose(Brand $brand): array
    {
        if (! $brand->isOperational()) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $own = SiteDomains::ownDomains($brand);
        $existing = BacklinkSource::query()->where('brand_id', $brand->id)->pluck('domain')
            ->merge(Backlink::query()->where('brand_id', $brand->id)->distinct()->pluck('source_domain'))->unique()->values()->all();
        try {
            $route = $this->routes->resolve(BacklinkSourcesAgent::OPERATION);
            if ($route->isEmpty()) {
                return ['status' => 'no_provider', 'added' => 0];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $agent = new BacklinkSourcesAgent;
            $structured = $agent->prompt(
                "DATA_JSON\n".json_encode([
                    'brand' => [
                        'name' => $brand->name, 'sector' => $brand->sectorCategory?->name, 'website' => $own[0] ?? null,
                        'main_services' => BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)
                            ->where('status', 'active')->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->limit(10)->get()
                            ->map(fn (BrandOffering $o): string => $o->displayName())->values()->all(),
                    ],
                    'areas' => BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->get()
                        ->map(fn (BrandServiceArea $a): array => ['city' => $a->city_name, 'district' => $a->district_name, 'name' => $a->name])->values()->all(),
                    'existing' => array_slice($existing, 0, 300),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 300,
            )->toArray();
        } catch (Throwable $error) {
            Log::warning('site.backlinks.sources_failed', ['brand' => $brand->id, 'error' => $error->getMessage()]);

            return ['status' => 'error', 'added' => 0];
        }

        $added = 0;
        $seen = array_flip($existing);
        foreach (array_slice((array) ($structured['sources'] ?? []), 0, (int) config('moxdop-site.backlinks.max_sources', 25)) as $row) {
            $source = self::validSource(is_array($row) ? $row : []);
            if ($source === null || isset($seen[$source['domain']]) || SiteDomains::isOwn($source['domain'], $own)) {
                continue;
            }
            $seen[$source['domain']] = true;
            BacklinkSource::query()->create($source + ['brand_id' => $brand->id, 'origin' => 'ai', 'status' => BacklinkSource::NONE]);
            $added++;
        }

        return ['status' => 'ready', 'added' => $added];
    }

    /**
     * One proposed source, validated: https URL with a host; kind from the list; a fee only with an evidence URL on the
     * same site (else "teyit").
     *
     * @param  array<string, mixed>  $row
     * @return array{name: string, url: string, domain: string, kind: string, fee: string, fee_evidence_url: ?string, reason: string}|null
     */
    public static function validSource(array $row): ?array
    {
        $url = trim((string) ($row['url'] ?? ''));
        $host = str_starts_with(mb_strtolower($url), 'https://') ? SiteDomains::host($url) : null;
        $name = trim((string) ($row['name'] ?? ''));
        if ($host === null || $name === '') {
            return null;
        }
        $fee = in_array($row['fee'] ?? null, ['ucretsiz', 'ucretli'], true) ? (string) $row['fee'] : 'teyit';
        $evidence = trim((string) ($row['fee_evidence_url'] ?? ''));
        $evidenceHost = preg_match('#^https?://#i', $evidence) === 1 ? SiteDomains::host($evidence) : null;
        if ($fee !== 'teyit' && ($evidenceHost === null || SiteDomains::registrable($evidenceHost) !== SiteDomains::registrable($host))) {
            $fee = 'teyit';
        }

        return [
            'name' => mb_substr($name, 0, 200),
            'url' => mb_substr($url, 0, 2000),
            'domain' => $host,
            'kind' => in_array($row['kind'] ?? null, BacklinkSourcesAgent::KINDS, true) ? (string) $row['kind'] : 'diger',
            'fee' => $fee,
            'fee_evidence_url' => $fee === 'teyit' ? null : mb_substr($evidence, 0, 2000),
            'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 240),
        ];
    }
}
