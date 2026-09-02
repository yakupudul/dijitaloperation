<?php

namespace App\Livewire\Operator\Library;

use App\Models\ServiceCatalogItem;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Hizmet Kütüphanesi')]
class ServiceCatalogPage extends Component
{
    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'active';

    #[Url(history: true)]
    public string $sector = '';

    public string $service_name = '';

    public string $service_sector = '';

    public string $service_description = '';

    public string $alias = '';

    public ?int $alias_service_id = null;

    public string $message = '';

    public function createService(ServiceCatalogService $catalog): void
    {
        $this->validate([
            'service_name' => ['required', 'string', 'max:255'],
            'service_sector' => ['nullable', 'string', 'max:120'],
            'service_description' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $catalog->resolveOrCreate(
            $this->service_name,
            $this->service_sector,
            $this->service_description,
            app()->getLocale(),
            auth()->user(),
        );

        $this->reset(['service_name', 'service_sector', 'service_description']);
        $this->message = $result['created'] ? 'Hizmet kütüphaneye eklendi.' : 'Bu hizmet zaten kütüphanede bulunuyor.';
    }

    public function beginAlias(int $serviceId): void
    {
        $this->alias_service_id = $serviceId;
        $this->alias = '';
        $this->resetValidation('alias');
    }

    public function addAlias(ServiceCatalogService $catalog): void
    {
        $this->validate([
            'alias_service_id' => ['required', 'integer', 'exists:service_catalog_items,id'],
            'alias' => ['required', 'string', 'max:255'],
        ]);

        $service = ServiceCatalogItem::query()->findOrFail($this->alias_service_id);
        $catalog->addAlias($service, $this->alias, app()->getLocale(), auth()->user());
        $this->reset(['alias_service_id', 'alias']);
        $this->message = 'Hizmet eş adı kaydedildi.';
    }

    public function toggleStatus(int $serviceId, ServiceCatalogService $catalog): void
    {
        $service = ServiceCatalogItem::query()->findOrFail($serviceId);
        $nextStatus = $service->status === 'active' ? 'archived' : 'active';
        $catalog->setStatus($service, $nextStatus, auth()->user());
        $this->message = $nextStatus === 'archived' ? 'Hizmet arşivlendi.' : 'Hizmet yeniden etkinleştirildi.';
    }

    public function render(): View
    {
        $query = ServiceCatalogItem::query()
            ->with(['primaryName', 'names' => fn ($query) => $query->where('is_active', true)->orderByDesc('is_primary')->orderBy('raw_label')])
            ->withCount(['brandOfferings', 'searchQueries']);

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }
        if ($this->sector !== '') {
            $query->where('sector', $this->sector);
        }
        if (trim($this->search) !== '') {
            $term = '%'.mb_strtolower(trim($this->search), 'UTF-8').'%';
            $query->whereHas('names', fn ($names) => $names->whereRaw('LOWER(raw_label) LIKE ?', [$term]));
        }

        return view('livewire.operator.library.service-catalog-page', [
            'services' => $query->orderBy('status')->orderBy('id')->limit(250)->get(),
            'sectorOptions' => IndustryOptions::options(),
            'statusOptions' => ['active' => 'Aktif', 'archived' => 'Arşiv', 'all' => 'Tümü'],
        ]);
    }
}
