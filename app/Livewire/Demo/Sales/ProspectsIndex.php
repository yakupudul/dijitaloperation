<?php

namespace App\Livewire\Demo\Sales;

use App\Services\Prospects\ProspectReadService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Prospects')]
class ProspectsIndex extends Component
{
    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = '';

    #[Url(history: true)]
    public string $source = '';

    #[Url(history: true)]
    public string $identity = '';

    #[Url(history: true)]
    public string $owner = '';

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->source = '';
        $this->identity = '';
        $this->owner = '';
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== ''
            || $this->status !== ''
            || $this->source !== ''
            || $this->identity !== ''
            || $this->owner !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function filteredRows(): array
    {
        $rows = app(ProspectReadService::class)->listRows();
        $needle = mb_strtolower(trim($this->search));

        return collect($rows)
            ->filter(function (array $row) use ($needle): bool {
                if ($needle !== '') {
                    $haystack = mb_strtolower(implode(' ', [
                        (string) ($row['company_name'] ?? ''),
                        (string) ($row['website_url'] ?? ''),
                        (string) ($row['owner_name'] ?? ''),
                    ]));
                    if (! str_contains($haystack, $needle)) {
                        return false;
                    }
                }

                if ($this->status !== '' && ($row['status'] ?? '') !== $this->status) {
                    return false;
                }

                if ($this->source !== '' && ($row['source'] ?? '') !== $this->source) {
                    return false;
                }

                if ($this->identity !== '' && ($row['identity_status'] ?? '') !== $this->identity) {
                    return false;
                }

                if ($this->owner !== '' && (string) ($row['owner_name'] ?? '') !== $this->owner) {
                    return false;
                }

                return true;
            })
            ->values()
            ->all();
    }

    public function render(): View
    {
        $rows = $this->filteredRows();
        $allRows = app(ProspectReadService::class)->listRows();

        $ownerOptions = collect($allRows)
            ->pluck('owner_name')
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(static fn (string $name): array => [$name => $name])
            ->all();

        return view('livewire.demo.sales.prospects-index', [
            'rows' => $rows,
            'allCount' => count($allRows),
            'hasFilters' => $this->hasActiveFilters(),
            'statusOptions' => ProspectReadService::statusOptions(),
            'sourceOptions' => ProspectReadService::sourceOptions(),
            'identityOptions' => ProspectReadService::identityOptions(),
            'ownerOptions' => $ownerOptions,
        ]);
    }
}
