<?php

namespace App\Livewire\Operator\Library;

use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use MoxDop\Website\Standards\WebsiteStandardCatalog;

#[Layout('operator.layouts.app')]
#[Title('Standartlar')]
final class WebsiteStandardsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $assetType = 'website';

    #[Url]
    public string $platform = '';

    #[Url]
    public string $group = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public string $message = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['assetType', 'platform', 'group', 'search', 'status'], true)) {
            $this->resetPage();
        }
        if ($property === 'assetType') {
            $this->group = '';
            $this->platform = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset('platform', 'group', 'search', 'status');
        $this->resetPage();
    }

    public function setEnabled(string $id, bool $enabled, WebsiteStandardCatalog $catalog): void
    {
        $catalog->setEnabled($id, $enabled, auth()->user());
        $this->message = 'Standart ayarı kaydedildi. Sonraki değerlendirmelerde uygulanacak.';
    }

    public function setSeverity(string $id, string $severity, WebsiteStandardCatalog $catalog): void
    {
        $catalog->setSeverity($id, $severity, auth()->user());
        $this->message = 'Önem derecesi kaydedildi. Mevcut sonuçlar korunur; sonraki değerlendirmelerde uygulanır.';
    }

    public function resetStandard(string $id, WebsiteStandardCatalog $catalog): void
    {
        $catalog->resetStandard($id, auth()->user());
        $this->message = 'Standardın varsayılan etkinlik ve önem ayarları geri yüklendi.';
    }

    public function render(WebsiteStandardCatalog $catalog): View
    {
        $all = collect($catalog->all())->filter(fn ($row) => $row['method'] !== 'expert_review');
        $scope = $all->filter(fn ($row) => $this->assetType === 'website'
            && ($this->platform === '' || ($row['platform'] ?? 'general') === $this->platform));
        $groups = collect(WebsiteStandardCatalog::GROUPS)->filter(fn ($label, $key) => $scope->contains('group', $key));
        $counts = $scope->groupBy('group')->map->count();
        $filtered = $scope->filter(fn ($row) =>
            ($this->group === '' || $row['group'] === $this->group)
            && ($this->status === '' || ($this->status === 'enabled' ? $row['enabled'] : ! $row['enabled']))
            && ($this->search === '' || mb_stripos($row['title'].' '.$row['criterion'].' '.$row['action'], trim($this->search)) !== false))
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $page = min(max(1, $this->getPage()), max(1, (int) ceil($filtered->count() / 20)));
        $standards = new LengthAwarePaginator($filtered->forPage($page, 20), $filtered->count(), 20, $page, ['path' => request()->url()]);
        $stats = ['total' => $scope->count(), 'enabled' => $scope->where('enabled', true)->count(),
            'verified' => $scope->where('classification', 'verified')->count(),
            'wordpress' => $all->where('platform', 'wordpress')->count()];

        return view('livewire.operator.library.website-standards-page', compact('standards', 'groups', 'counts', 'stats'));
    }
}
