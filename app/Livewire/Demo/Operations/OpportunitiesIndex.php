<?php

namespace App\Livewire\Demo\Operations;

use App\Support\Demo\CommercialContextFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\OpportunityFixtures;
use App\Support\Options\AgencyServiceOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Opportunities')]
class OpportunitiesIndex extends Component
{
    #[Url(history: true)]
    public string $view = 'open';

    #[Url(history: true)]
    public string $brand = '';

    #[Url(history: true)]
    public string $customer = '';

    #[Url(history: true)]
    public string $asset_type = '';

    #[Url(history: true)]
    public string $category = '';

    #[Url(history: true)]
    public string $service = '';

    #[Url(history: true)]
    public string $q = '';

    public ?string $selectedId = null;

    public function mount(): void
    {
        if ($this->brand === DemoCatalog::BRAND_ID) {
            // keep explicit brand filter from URL
        }
    }

    public function setView(string $view): void
    {
        $allowed = ['open', 'new', 'reviewing', 'deferred', 'converted', 'dismissed', 'all'];
        if (in_array($view, $allowed, true)) {
            $this->view = $view;
        }
    }

    public function setBrand(string $brandId): void
    {
        $this->brand = $brandId;
    }

    public function setCustomer(string $customerId): void
    {
        $this->customer = $customerId;
    }

    public function setAssetType(string $type): void
    {
        $this->asset_type = $type;
    }

    public function setCategory(string $category): void
    {
        $this->category = $category;
    }

    public function setService(string $service): void
    {
        $this->service = $service;
    }

    public function clearFilters(): void
    {
        $this->brand = '';
        $this->customer = '';
        $this->asset_type = '';
        $this->category = '';
        $this->service = '';
        $this->q = '';
    }

    public function selectOpportunity(string $id): void
    {
        $this->selectedId = $this->selectedId === $id ? null : $id;
    }

    public function review(string $id): void
    {
        DemoState::setOpportunityStatus($id, 'reviewing');
    }

    public function defer(string $id): void
    {
        DemoState::setOpportunityStatus($id, 'deferred');
    }

    public function dismiss(string $id): void
    {
        DemoState::setOpportunityStatus($id, 'dismissed');
    }

    public function createRecommendation(string $id): void
    {
        DemoState::createRecommendationFromOpportunity($id);
    }

    public function render(): View
    {
        $all = DemoState::opportunitiesWithStatus();
        $filtered = collect($all);

        if ($this->view === 'open') {
            $filtered = $filtered->where('status', 'open');
        } elseif ($this->view === 'new') {
            $filtered = $filtered->where('is_new', true)->whereIn('status', ['open', 'reviewing']);
        } elseif ($this->view !== 'all') {
            $filtered = $filtered->where('status', $this->view);
        }

        if ($this->brand !== '') {
            $filtered = $filtered->where('brand_id', $this->brand);
        }

        if ($this->customer !== '') {
            $filtered = $filtered->where('customer_id', $this->customer);
        }

        if ($this->asset_type !== '') {
            $filtered = $filtered->filter(fn (array $row): bool => in_array($this->asset_type, $row['asset_types'] ?? [], true));
        }

        if ($this->category !== '') {
            $filtered = $filtered->where('category', $this->category);
        }

        if ($this->service !== '') {
            $filtered = $filtered->where('service_code', $this->service);
        }

        if ($this->q !== '') {
            $q = mb_strtolower(trim($this->q));
            $filtered = $filtered->filter(function (array $row) use ($q): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $row['title'] ?? '',
                    $row['brand_name'] ?? '',
                    $row['goal_title'] ?? '',
                    $row['offering'] ?? '',
                ]));

                return str_contains($haystack, $q);
            });
        }

        $rows = OpportunityFixtures::sortByBusinessRelevance($filtered->values()->all());
        $glance = OpportunityFixtures::glance($all);

        $selected = null;
        if ($this->selectedId !== null) {
            $selected = collect($rows)->firstWhere('id', $this->selectedId)
                ?? collect($all)->firstWhere('id', $this->selectedId);
        }

        $brandOptions = collect($all)
            ->mapWithKeys(fn (array $row): array => [($row['brand_id'] ?? '') => ($row['brand_name'] ?? '')])
            ->filter()
            ->unique()
            ->all();

        $customerOptions = collect($all)
            ->mapWithKeys(fn (array $row): array => [($row['customer_id'] ?? '') => ($row['customer_name'] ?? '')])
            ->filter()
            ->unique()
            ->all();

        $serviceOptions = collect($all)
            ->mapWithKeys(fn (array $row): array => [($row['service_code'] ?? '') => ($row['service_label'] ?? '')])
            ->filter()
            ->unique()
            ->all();

        $categories = ['demand', 'content', 'paid', 'creative', 'local', 'conversion', 'cross_channel'];

        return view('livewire.demo.operations.opportunities-index', [
            'opportunities' => $rows,
            'glance' => $glance,
            'selected' => $selected,
            'brandOptions' => $brandOptions,
            'customerOptions' => $customerOptions,
            'serviceOptions' => $serviceOptions,
            'categories' => $categories,
            'serviceScopeLabels' => AgencyServiceOptions::labels(
                collect(CommercialContextFixtures::serviceScopeForCustomer(DemoCatalog::CUSTOMER_ID))
                    ->pluck('service_code')
                    ->filter(fn (?string $code): bool => $code !== null && $code !== 'instagram_management')
                    ->values()
                    ->all()
            ),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
