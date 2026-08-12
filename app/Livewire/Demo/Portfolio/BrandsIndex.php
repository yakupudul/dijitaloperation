<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\DigitalAssetTypes;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Brands')]
class BrandsIndex extends Component
{
    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $customer = '';

    #[Url(history: true)]
    public string $sector = '';

    #[Url(history: true)]
    public string $primary_market = '';

    #[Url(history: true)]
    public string $asset_type = '';

    #[Url(history: true)]
    public string $responsible = '';

    #[Url(history: true)]
    public string $attention = '';

    #[Url(history: true)]
    public string $context = '';

    #[Url(history: true)]
    public string $sort = 'name';

    #[Url(history: true)]
    public string $dir = 'asc';

    public bool $showOptionalColumns = false;

    public function clearFilters(): void
    {
        $this->search = '';
        $this->customer = '';
        $this->sector = '';
        $this->primary_market = '';
        $this->asset_type = '';
        $this->responsible = '';
        $this->attention = '';
        $this->context = '';
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->customer !== ''
            || $this->sector !== ''
            || $this->primary_market !== ''
            || $this->asset_type !== ''
            || $this->responsible !== ''
            || $this->attention !== ''
            || $this->context !== '';
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sort = $column;
        $this->dir = 'asc';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function enrichedBrands(): array
    {
        $customers = collect(DemoState::all()['customers'] ?? [])->keyBy('id');
        $team = collect(DemoCatalog::teamMembers())->keyBy('id');
        $allAssets = array_merge(DemoCatalog::assets(), DemoState::all()['demo_assets'] ?? []);

        return collect(DemoState::all()['brands'] ?? [])
            ->map(function (array $brand) use ($customers, $team, $allAssets): array {
                $brand = DemoState::normalizeBrand($brand);
                $customer = $customers[$brand['customer_id'] ?? ''] ?? DemoCatalog::customer();
                $brandAssets = collect($allAssets)->filter(
                    fn (array $asset): bool => ($asset['brand_id'] ?? '') === ($brand['id'] ?? '')
                        || (($brand['id'] ?? '') === DemoCatalog::BRAND_ID && ($asset['brand_id'] ?? DemoCatalog::BRAND_ID) === DemoCatalog::BRAND_ID)
                );
                if (($brand['id'] ?? '') === DemoCatalog::BRAND_ID && $brandAssets->isEmpty()) {
                    $brandAssets = collect(DemoCatalog::assets());
                }

                $connected = $brandAssets->filter(function (array $asset): bool {
                    $health = $asset['health'] ?? '';
                    $provenance = strtolower((string) ($asset['provenance'] ?? ''));

                    return $health !== 'warning' && (str_contains($provenance, 'connected') || ($asset['health'] ?? '') === 'healthy' || ($asset['health'] ?? '') === 'needs_attention');
                })->count();

                $openFindings = (int) ($brand['open_findings'] ?? $brandAssets->sum(fn (array $a): int => (int) ($a['open_findings'] ?? 0)));
                $openTasks = (int) ($brand['open_tasks'] ?? 0);
                $overdue = (int) ($brand['overdue_tasks'] ?? 0);
                $completed = (int) ($brand['context_completed'] ?? 0);
                $total = (int) ($brand['context_total'] ?? 8);
                if (($brand['id'] ?? '') === DemoCatalog::BRAND_ID) {
                    $ctx = DemoCatalog::brandBusinessContext();
                    $completed = (int) $ctx['completed'];
                    $total = (int) $ctx['total'];
                    $openFindings = max($openFindings, (int) ($brand['open_findings'] ?? 4));
                }

                $needsAttention = $openFindings > 0 || $overdue > 0 || ($brand['health'] ?? '') === 'needs_attention';

                $brand['customer_name'] = $customer['name'] ?? '—';
                $brand['sector_label'] = IndustryOptions::label($brand['sector'] ?? null);
                $brand['primary_market_label'] = CountryOptions::label($brand['primary_country'] ?? null);
                $brand['extra_markets'] = max(0, count($brand['target_markets'] ?? []) - 1);
                $brand['assets_count'] = max((int) ($brand['assets_count'] ?? 0), $brandAssets->count());
                $brand['connected_assets'] = (int) ($brand['connected_assets'] ?? $connected);
                $brand['open_findings'] = $openFindings;
                $brand['open_tasks'] = $openTasks;
                $brand['overdue_tasks'] = $overdue;
                $brand['context_completed'] = $completed;
                $brand['context_total'] = $total;
                $brand['context_ratio'] = $total > 0 ? $completed / $total : 0;
                $brand['needs_attention'] = $needsAttention;
                $brand['asset_types'] = $brandAssets->pluck('type')->unique()->values()->all();
                $brand['responsible'] = collect($brand['responsible_user_ids'] ?? [])
                    ->map(fn (string $id) => $team[$id] ?? null)
                    ->filter()
                    ->values()
                    ->all();
                $brand['initials'] = collect(explode(' ', (string) ($brand['name'] ?? '')))
                    ->map(fn (string $part): string => mb_substr($part, 0, 1))
                    ->take(2)
                    ->implode('');

                return $brand;
            })
            ->values()
            ->all();
    }

    public function render(): View
    {
        $rows = collect($this->enrichedBrands());

        if ($this->search !== '') {
            $q = mb_strtolower($this->search);
            $rows = $rows->filter(function (array $brand) use ($q): bool {
                $hay = mb_strtolower(implode(' ', array_filter([
                    $brand['name'] ?? '',
                    $brand['customer_name'] ?? '',
                    $brand['sector_label'] ?? '',
                    $brand['website'] ?? '',
                ])));

                return str_contains($hay, $q);
            });
        }

        if ($this->customer !== '') {
            $rows = $rows->filter(fn (array $b): bool => ($b['customer_id'] ?? '') === $this->customer);
        }
        if ($this->sector !== '') {
            $rows = $rows->filter(fn (array $b): bool => ($b['sector'] ?? '') === $this->sector);
        }
        if ($this->primary_market !== '') {
            $rows = $rows->filter(fn (array $b): bool => ($b['primary_country'] ?? '') === $this->primary_market);
        }
        if ($this->asset_type !== '') {
            $rows = $rows->filter(fn (array $b): bool => in_array($this->asset_type, $b['asset_types'] ?? [], true));
        }
        if ($this->responsible !== '') {
            $rows = $rows->filter(fn (array $b): bool => in_array($this->responsible, $b['responsible_user_ids'] ?? [], true));
        }
        if ($this->attention === 'needs_attention') {
            $rows = $rows->filter(fn (array $b): bool => (bool) ($b['needs_attention'] ?? false));
        } elseif ($this->attention === 'clear') {
            $rows = $rows->filter(fn (array $b): bool => ! ($b['needs_attention'] ?? false));
        }
        if ($this->context === 'complete') {
            $rows = $rows->filter(fn (array $b): bool => ($b['context_ratio'] ?? 0) >= 0.75);
        } elseif ($this->context === 'incomplete') {
            $rows = $rows->filter(fn (array $b): bool => ($b['context_ratio'] ?? 0) > 0 && ($b['context_ratio'] ?? 0) < 0.75);
        } elseif ($this->context === 'not_started') {
            $rows = $rows->filter(fn (array $b): bool => ($b['context_completed'] ?? 0) === 0);
        }

        $sort = $this->sort;
        $dir = $this->dir === 'desc' ? 'desc' : 'asc';
        $rows = $rows->sortBy(function (array $b) use ($sort) {
            return match ($sort) {
                'customer' => mb_strtolower((string) ($b['customer_name'] ?? '')),
                'sector' => mb_strtolower((string) ($b['sector_label'] ?? '')),
                'assets' => (int) ($b['assets_count'] ?? 0),
                'findings' => (int) ($b['open_findings'] ?? 0),
                'tasks' => (int) ($b['open_tasks'] ?? 0),
                default => mb_strtolower((string) ($b['name'] ?? '')),
            };
        }, SORT_REGULAR, $dir === 'desc')->values();

        $all = collect($this->enrichedBrands());
        $assetTypeOptions = DigitalAssetTypes::options() + [
            'ga4' => 'Google Analytics',
            'gsc' => 'Search Console',
            'gbp' => 'Google Business Profile',
            'domain' => 'Domain',
            'hosting' => 'Hosting',
        ];

        return view('livewire.demo.portfolio.brands-index', [
            'brands' => $rows->all(),
            'allCount' => $all->count(),
            'summaryLine' => sprintf(
                '%d brands · %d digital assets · %d brands need attention',
                $all->count(),
                $all->sum(fn (array $b): int => (int) ($b['assets_count'] ?? 0)),
                $all->filter(fn (array $b): bool => (bool) ($b['needs_attention'] ?? false))->count()
            ),
            'hasFilters' => $this->hasActiveFilters(),
            'customerOptions' => collect(DemoState::all()['customers'] ?? [])
                ->mapWithKeys(fn (array $c): array => [($c['id'] ?? '') => ($c['name'] ?? '')])
                ->all(),
            'sectorOptions' => IndustryOptions::options(),
            'countryOptions' => CountryOptions::options(),
            'assetTypeOptions' => $assetTypeOptions,
            'teamOptions' => collect(DemoCatalog::teamMembers())
                ->mapWithKeys(fn (array $m): array => [$m['id'] => $m['name']])
                ->all(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
