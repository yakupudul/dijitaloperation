<?php

namespace App\Livewire\Demo\Operations;

use App\Enums\RecommendationOrigin;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Services\Opportunities\OpportunityDispositionService;
use App\Services\Opportunities\OpportunityReadService;
use App\Services\Recommendations\CreateRecommendationFromOpportunity;
use App\Support\Demo\DemoState;
use App\Support\Demo\OpportunityFixtures;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Production Opportunities queue — backed by App\Models\Opportunity via OpportunityReadService.
 * No Demo fixtures: empty result set means no rows exist yet for the current filters.
 */
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
        $this->applyDisposition(
            $id,
            static fn (Opportunity $opportunity): Opportunity => app(OpportunityDispositionService::class)->review($opportunity),
            'Opportunity marked for review.'
        );
    }

    public function defer(string $id): void
    {
        $this->applyDisposition(
            $id,
            static fn (Opportunity $opportunity): Opportunity => app(OpportunityDispositionService::class)->defer($opportunity),
            'Opportunity deferred.'
        );
    }

    public function dismiss(string $id): void
    {
        $this->applyDisposition(
            $id,
            static fn (Opportunity $opportunity): Opportunity => app(OpportunityDispositionService::class)->dismiss($opportunity),
            'Opportunity dismissed.'
        );
    }

    /**
     * Marks the Opportunity converted and creates the one Opportunity-sourced Recommendation.
     * No Task, Work item, or Approval is created here — Work alignment is a later Prompt.
     * The idempotency key makes a double click a no-op instead of a duplicate row.
     */
    public function createRecommendation(string $id): void
    {
        $opportunity = $this->resolveOpportunity($id);
        if ($opportunity === null) {
            return;
        }

        app(OpportunityDispositionService::class)->markConvertedWithoutRecommendation($opportunity);

        app(CreateRecommendationFromOpportunity::class)->create(
            $opportunity,
            [
                'title' => 'Act on: '.$opportunity->title,
                'action' => $opportunity->description ?? $opportunity->title,
                'rationale' => $opportunity->description,
                'priority' => $this->priorityForOpportunity($opportunity),
                'status' => Recommendation::STATUS_OPEN,
            ],
            RecommendationOrigin::Operator,
            auth()->user(),
            'opportunity-convert:'.$opportunity->id,
        );

        DemoState::flash('Opportunity converted into a Recommendation. No Task was created.');
    }

    private function priorityForOpportunity(Opportunity $opportunity): string
    {
        return match ($opportunity->qualitative_priority) {
            'critical' => 'critical',
            'high' => 'high',
            'low' => 'low',
            default => 'medium',
        };
    }

    private function applyDisposition(string $id, Closure $action, string $message): void
    {
        $opportunity = $this->resolveOpportunity($id);
        if ($opportunity === null) {
            return;
        }

        $action($opportunity);
        DemoState::flash($message);
    }

    private function resolveOpportunity(string $id): ?Opportunity
    {
        if (! ctype_digit($id)) {
            return null;
        }

        return Opportunity::query()->find((int) $id);
    }

    public function render(): View
    {
        $all = app(OpportunityReadService::class)->forListPresentation();
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

        $categories = ['visibility', 'growth', 'demand', 'content', 'paid', 'creative', 'local', 'conversion', 'cross_channel'];

        return view('livewire.demo.operations.opportunities-index', [
            'opportunities' => $rows,
            'glance' => $glance,
            'selected' => $selected,
            'brandOptions' => $brandOptions,
            'customerOptions' => $customerOptions,
            'serviceOptions' => $serviceOptions,
            'categories' => $categories,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
