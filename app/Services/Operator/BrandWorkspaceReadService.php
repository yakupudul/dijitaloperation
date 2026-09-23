<?php

namespace App\Services\Operator;

use App\Enums\SeoTaskStatus;
use App\Enums\SeoTaskType;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read model for the brand page: real connection state (confirmed account bindings, last successful
 * sync), the setup checklist and the brand's services. Missing data is reported as missing, never 0.
 */
final class BrandWorkspaceReadService
{
    /** Connected-account labels (operators never see "binding" / "capability"). */
    public const array ACCOUNT_LABELS = [
        'search_console' => 'Search Console',
        'ga4' => 'Google Analytics 4',
        'google_business_profile' => 'İşletme Profili',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
    ];

    /**
     * @return list<array{id: int, name: string, type: string, type_label: string, url: string, primary_url: ?string, connected: bool, accounts: list<array{capability: string, label: string, resource: string, last_sync: ?CarbonImmutable}>, open_findings: int}>
     */
    public function assets(Brand $brand): array
    {
        $assets = $brand->digitalAssets()
            ->with(['assetBindings' => fn ($q) => $q->where('status', 'active')->with('externalResource')])
            ->withCount(['findings as open_findings_count' => fn ($q) => $q->where('status', 'open')])
            ->whereNotIn('type', ['domain', 'hosting'])
            ->orderBy('id')
            ->get();
        $resourceIds = $assets->flatMap(fn (DigitalAsset $a) => $a->assetBindings->pluck('external_resource_id'))->filter()->unique()->values()->all();
        $lastSync = $this->lastSync($resourceIds);

        return $assets->map(function (DigitalAsset $asset) use ($lastSync): array {
            $presented = OperatorPortfolioPresenter::asset($asset);
            $accounts = $asset->assetBindings->map(fn (CoreAssetBinding $binding): array => [
                'capability' => (string) $binding->capability,
                'label' => self::ACCOUNT_LABELS[$binding->capability] ?? (string) $binding->capability,
                'resource' => (string) ($binding->externalResource?->display_name ?? '—'),
                'last_sync' => $lastSync[$binding->external_resource_id] ?? null,
            ])->values()->all();

            return [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'type' => (string) $asset->type,
                'type_label' => (string) $presented['type_label'],
                'url' => (string) $presented['url'],
                'primary_url' => $asset->primary_url,
                'connected' => $accounts !== [],
                'accounts' => $accounts,
                'open_findings' => (int) $asset->open_findings_count,
            ];
        })->values()->all();
    }

    /**
     * What is set up and what is missing. Required items block "setup complete"; channel accounts the
     * brand may simply not have (Business Profile, Ads, Meta) are optional.
     *
     * @param  list<array<string, mixed>>  $assets  output of assets()
     * @param  list<array<string, mixed>>  $services  output of services()
     * @return array{items: list<array{key: string, label: string, done: bool, required: bool, detail: string}>, complete: bool, done: int, total: int}
     */
    public function checklist(Brand $brand, array $assets, array $services): array
    {
        $capabilities = [];
        foreach ($assets as $asset) {
            foreach ($asset['accounts'] as $account) {
                $capabilities[$account['capability']] = $account['resource'];
            }
        }
        $website = collect($assets)->firstWhere('type', 'website');
        $areas = $brand->serviceAreas()->where('status', 'active')->count();
        $withoutMatching = collect($services)->filter(fn (array $s): bool => $s['catalog_item_id'] !== null && $s['matching_count'] === 0)->count();

        $items = [
            ['key' => 'website', 'label' => 'Web sitesi', 'done' => $website !== null, 'required' => true,
                'detail' => $website !== null ? (string) ($website['primary_url'] ?: $website['name']) : 'Web sitesi varlığı yok.'],
        ];
        foreach (self::ACCOUNT_LABELS as $capability => $label) {
            $required = in_array($capability, ['search_console', 'ga4'], true);
            $items[] = ['key' => $capability, 'label' => $label, 'done' => isset($capabilities[$capability]), 'required' => $required,
                'detail' => $capabilities[$capability] ?? ($required ? 'Bağlı hesap yok.' : 'Bağlı hesap yok (markada yoksa sorun değil).')];
        }
        $items[] = ['key' => 'services', 'label' => 'Hizmetler', 'done' => $services !== [], 'required' => true,
            'detail' => $services !== [] ? count($services).' hizmet · '.collect($services)->where('is_priority', true)->count().' öncelikli' : 'Markaya hizmet eklenmedi.'];
        $items[] = ['key' => 'matching', 'label' => 'Eşleştirme ifadeleri', 'done' => $services !== [] && $withoutMatching === 0, 'required' => true,
            'detail' => $services === [] ? 'Önce hizmet ekle.' : ($withoutMatching === 0 ? 'Her hizmette var.' : $withoutMatching.' hizmette yok; sorgular bu hizmetlere otomatik atanmaz.')];
        $items[] = ['key' => 'areas', 'label' => 'Hizmet verdiği yerler', 'done' => $areas > 0, 'required' => true,
            'detail' => $areas > 0 ? $areas.' bölge' : 'Tanımlı değil; bölge dışı aramalar ayrılamaz.'];

        $required = array_filter($items, static fn (array $i): bool => $i['required']);

        return [
            'items' => $items,
            'complete' => collect($required)->every(fn (array $i): bool => $i['done']),
            'done' => count(array_filter($required, static fn (array $i): bool => $i['done'])),
            'total' => count($required),
        ];
    }

    /**
     * @return list<array{id: int, name: string, is_priority: bool, catalog_item_id: ?int, sector: ?string, matching_count: int, matching: list<string>}>
     */
    public function services(Brand $brand): array
    {
        return BrandOffering::query()
            ->with(['primaryName', 'catalogItem.matchingKeywords'])
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->orderByRaw('CASE WHEN is_priority THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN priority_rank IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority_rank')
            ->orderBy('id')
            ->get()
            ->map(fn (BrandOffering $offering): array => [
                'id' => (int) $offering->id,
                'name' => (string) ($offering->primaryName?->raw_label ?? 'Hizmet #'.$offering->id),
                'is_priority' => (bool) $offering->is_priority,
                'catalog_item_id' => $offering->service_catalog_item_id !== null ? (int) $offering->service_catalog_item_id : null,
                'sector' => $offering->catalogItem?->sector,
                'matching_count' => $offering->catalogItem?->matchingKeywords->count() ?? 0,
                'matching' => $offering->catalogItem?->matchingKeywords->pluck('label')->take(8)->values()->all() ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * Open SEO tasks of the brand's websites: content suggestions, critical fixes, open questions.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return array{content: int, critical: int, questions: int, website_id: ?int}
     */
    public function seo(array $assets): array
    {
        $websiteIds = collect($assets)->where('type', 'website')->pluck('id')->all();
        if ($websiteIds === [] || ! Schema::hasTable('seo_tasks')) {
            return ['content' => 0, 'critical' => 0, 'questions' => 0, 'website_id' => null];
        }
        $open = SeoTask::query()->whereIn('digital_asset_id', $websiteIds)->where('status', SeoTaskStatus::Open->value)->get(['type', 'severity']);

        return [
            'content' => $open->where('type', SeoTaskType::Create)->count(),
            'critical' => $open->filter(fn (SeoTask $t): bool => $t->type === SeoTaskType::Fix && in_array($t->severity, ['critical', 'high'], true))->count(),
            'questions' => $open->where('type', SeoTaskType::Question)->count(),
            'website_id' => (int) $websiteIds[0],
        ];
    }

    /**
     * Last successful collection per external resource (completed or partial runs).
     *
     * @param  list<int>  $resourceIds
     * @return array<int, CarbonImmutable>
     */
    private function lastSync(array $resourceIds): array
    {
        if ($resourceIds === [] || ! Schema::hasTable('collection_resource_runs')) {
            return [];
        }

        return DB::table('collection_resource_runs')
            ->whereIn('external_resource_id', $resourceIds)
            ->whereIn('status', ['completed', 'partial'])
            ->whereNotNull('finished_at')
            ->groupBy('external_resource_id')
            ->selectRaw('external_resource_id, max(finished_at) as finished_at')
            ->pluck('finished_at', 'external_resource_id')
            ->map(fn ($value): CarbonImmutable => CarbonImmutable::parse($value))
            ->all();
    }
}
