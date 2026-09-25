<?php

namespace App\Services\Brain\Proposals\Kinds;

use App\Ai\Agents\Brain\AccountMappingAgent;
use App\Models\BrainProposal;
use App\Models\CoreAssetBinding;
use App\Models\ResourceAutomation;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Brain\BrainAi;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\SearchDemand\LibraryImportWorkflow;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Hesap eşleme": AI reads each Google account's most frequent queries and proposes its sector and services, so the
 * automatic query intake (Veri kaynakları → Sorgular) knows where to file them. The system then checks the answer:
 * the confidence is the share of the account's query impressions that the chosen services' matching expressions
 * actually cover — not the model's own opinion. Approving saves the mapping through the normal automation settings.
 */
final class AccountMappingKind implements ProposalKind
{
    public const string KIND = 'account_mapping';

    private const array QUERY_SOURCES = ['google_ads', 'search_console', 'google_business_profile'];

    public function __construct(
        private readonly BrainAi $ai,
        private readonly ProposalService $proposals,
        private readonly LibraryImportWorkflow $workflow,
        private readonly ServiceKeywordService $keywords,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Hesap → sektör ve hizmet eşlemesi';
    }

    public function usesAi(): bool
    {
        return true;
    }

    public function resultNoun(): string
    {
        return 'yeni öneri';
    }

    public function prepare(array $options): int
    {
        $automations = ResourceAutomation::query()->with('resource')
            ->whereHas('resource', fn ($q) => $q->whereIn('resource_type', self::QUERY_SOURCES))
            ->when(! empty($options['automation_ids']), fn ($q) => $q->whereIn('id', array_map('intval', (array) $options['automation_ids'])))
            ->when(empty($options['automation_ids']) && empty($options['include_mapped']), fn ($q) => $q->where(fn ($w) => $w->whereNull('sector')->orWhereNull('service_ids')->orWhereJsonLength('service_ids', 0)))
            ->orderBy('id')->limit(60)->get();
        if ($automations->isEmpty()) {
            return 0;
        }
        $sectors = ServiceCategory::query()->orderBy('name')->get(['code', 'name']);
        $services = ServiceCatalogItem::query()->where('status', 'active')->with('primaryName')->get(['id', 'sector']);
        $accounts = $automations->mapWithKeys(fn (ResourceAutomation $a): array => [(int) $a->id => $this->account($a)])->filter(fn (array $a): bool => $a['queries'] !== []);
        $created = 0;
        foreach ($accounts->chunk(8, true) as $chunk) {
            $answer = $this->ai->ask(new AccountMappingAgent, AiRouteKeys::BRAIN_ACCOUNT_MAPPING, [
                'sectors' => $sectors->map(fn ($s): array => ['code' => $s->code, 'label' => $s->name])->values()->all(),
                'services' => $services->map(fn ($s): array => ['id' => (int) $s->id, 'sector' => (string) $s->sector, 'name' => (string) ($s->primaryName?->raw_label ?? '')])->values()->all(),
                'accounts' => $chunk->map(fn (array $a, int $id): array => ['id' => $id] + $a)->values()->all(),
            ]);
            foreach ((array) ($answer['items'] ?? []) as $item) {
                $created += $this->proposeFor($automations->firstWhere('id', (int) ($item['account_id'] ?? 0)), $accounts, $services, $sectors, (array) $item) ? 1 : 0;
            }
        }

        return $created;
    }

    public function apply(BrainProposal $proposal, User $actor): void
    {
        $automation = ResourceAutomation::query()->findOrFail($proposal->subject_id);
        $proposed = $proposal->proposed;
        $queryCapable = in_array($automation->resource?->resource_type, ['google_ads', 'search_console'], true);
        app(ResourceAutomationService::class)->save($automation->id, [
            'collection_enabled' => (bool) $automation->collection_enabled,
            'interval_days' => (string) ($automation->interval_days ?: 1),
            'preferred_hour' => $automation->preferred_hour,
            'query_enabled' => $queryCapable ? true : (bool) $automation->query_enabled,
            'sector' => (string) $proposed['sector'],
            'service_ids' => array_map('intval', (array) ($proposed['service_ids'] ?? [])),
        ], (int) $automation->revision, $actor);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @param  Collection<int, ServiceCatalogItem>  $services
     * @param  Collection<int, ServiceCategory>  $sectors
     * @param  array<string, mixed>  $item
     */
    private function proposeFor(?ResourceAutomation $automation, Collection $accounts, Collection $services, Collection $sectors, array $item): bool
    {
        $sector = (string) ($item['sector'] ?? '');
        if ($automation === null || $sector === '' || ! $sectors->contains('code', $sector)) {
            return false;
        }
        $allowed = $services->where('sector', $sector)->pluck('id')->map('intval')->all();
        $ids = array_values(array_unique(array_intersect(array_map('intval', (array) ($item['service_ids'] ?? [])), $allowed)));
        $account = $accounts->get((int) $automation->id);
        $confidence = $this->coverage($account['queries'] ?? [], $ids);
        $names = $services->whereIn('id', $ids)->map(fn ($s): string => (string) ($s->primaryName?->raw_label ?? '#'.$s->id))->values()->all();
        $sectorLabel = (string) $sectors->firstWhere('code', $sector)?->name;

        return $this->proposals->propose(self::KIND, 'resource_automation', (int) $automation->id, $account['brand_id'] ?? null,
            ($account['name'] ?? 'Hesap').' → '.$sectorLabel.($names !== [] ? ': '.implode(', ', array_slice($names, 0, 6)).(count($names) > 6 ? ' +'.(count($names) - 6) : '') : ''),
            ['sector' => $automation->sector, 'service_ids' => array_map('intval', $automation->service_ids ?? [])],
            ['sector' => $sector, 'sector_label' => $sectorLabel, 'service_ids' => $ids, 'service_names' => $names],
            trim((string) ($item['reason'] ?? '')).($confidence !== null ? ' Eşleme ifadeleri sorgu gösterimlerinin %'.(int) round($confidence * 100).'\'ini kapsıyor.' : ''),
            $confidence ?? 0.5, 'ai') !== null;
    }

    /**
     * Share of the account's query impressions that the chosen services' matching expressions cover.
     *
     * @param  list<array{text: string, impressions: int}>  $queries
     * @param  list<int>  $serviceIds
     */
    private function coverage(array $queries, array $serviceIds): ?float
    {
        if ($serviceIds === [] || $queries === []) {
            return null;
        }
        $words = ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $serviceIds)->get();
        if ($words->isEmpty()) {
            return null;
        }
        $total = 0;
        $covered = 0;
        foreach ($queries as $query) {
            $weight = max(1, (int) $query['impressions']);
            $total += $weight;
            if ($this->keywords->matches($query['text'], $serviceIds, $words) !== []) {
                $covered += $weight;
            }
        }

        return $total > 0 ? $covered / $total : null;
    }

    /** @return array{name: string, type: string, brand: ?string, brand_id: ?int, website: ?string, queries: list<array{text: string, impressions: int}>} */
    private function account(ResourceAutomation $automation): array
    {
        $resource = $automation->resource;
        $binding = CoreAssetBinding::query()->where('external_resource_id', $resource->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->with('digitalAsset.brand')->first();
        $brand = $binding?->digitalAsset?->brand;
        [$table, $column] = $this->workflow->providerTable($resource->resource_type);
        $queries = [];
        try {
            $queries = DB::table($table)->where('external_resource_id', $resource->id)->whereNotNull($column)
                ->groupBy($column)->orderByDesc(DB::raw('sum(impressions)'))->limit(40)
                ->get([$column.' as text', DB::raw('sum(impressions) as impressions')])
                ->map(fn ($r): array => ['text' => mb_substr((string) $r->text, 0, 120), 'impressions' => (int) $r->impressions])->all();
        } catch (\Throwable $exception) {
            report(new RuntimeException('Account queries could not be read: '.$exception->getMessage()));
        }

        return [
            'name' => (string) ($resource->display_name ?: $resource->external_id),
            'type' => (string) $resource->resource_type,
            'brand' => $brand?->name,
            'brand_id' => $brand?->id ? (int) $brand->id : null,
            'website' => $binding?->digitalAsset?->domain ?: $binding?->digitalAsset?->primary_url,
            'queries' => $queries,
        ];
    }
}
