<?php

namespace App\Livewire\Operator\Library;

use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\SearchDemand\ServiceCatalogService;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Hizmetler')]
class ServiceCatalogPage extends Component
{
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(history: true)]
    public string $sector = '';

    #[Locked]
    public ?int $editingId = null;

    public bool $editorOpen = false;
    public bool $categoriesOpen = false;
    public string $service_name = '';
    public string $service_sector = '';
    public string $service_description = '';
    public string $alias = '';
    public string $matching_words = '';
    public bool $bulkOpen = false;
    public string $bulk_text = '';
    public string $bulk_sector = '';
    public string $message = '';

    #[Locked]
    public ?int $categoryId = null;

    public string $categoryName = '';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function updatedSector(): void { $this->resetPage(); }

    public function createService(): void
    {
        $this->reset(['editingId', 'service_name', 'service_sector', 'service_description', 'alias', 'matching_words']);
        $this->service_sector = $this->sector !== '__none' ? $this->sector : '';
        $this->resetValidation();
        $this->editorOpen = true;
        $this->categoriesOpen = false;
    }

    public function editService(int $id): void
    {
        $service = ServiceCatalogItem::query()->with('primaryName')->findOrFail($id);
        $this->editingId = $service->id;
        $this->service_name = $service->primaryName?->raw_label ?? '';
        $this->service_sector = $service->sector ?? '';
        $this->service_description = $service->description ?? '';
        $this->alias = '';
        $this->matching_words = $service->matchingKeywords()->pluck('label')->implode("\n");
        $this->resetValidation();
        $this->editorOpen = true;
        $this->categoriesOpen = false;
    }

    public function closeEditor(): void
    {
        $this->editorOpen = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    public function saveService(ServiceCatalogService $catalog): void
    {
        $this->service_name = trim($this->service_name);
        $this->validate([
            'service_name' => ['required', 'string', 'max:255'],
            'service_sector' => ['nullable', Rule::exists('service_categories', 'code')],
            'service_description' => ['nullable', 'string', 'max:2000'],
            'matching_words' => ['nullable', 'string', 'max:50000'],
        ]);
        DB::transaction(function () use ($catalog): void {
            if ($this->editingId !== null) {
                $service = ServiceCatalogItem::query()->findOrFail($this->editingId);
                try {
                    $catalog->update($service, $this->service_name, $this->service_sector, $this->service_description, auth()->user());
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['service_name' => collect($exception->errors())->flatten()->first()]);
                }
            } else {
                $result = $catalog->resolveOrCreate($this->service_name, $this->service_sector, $this->service_description, app()->getLocale(), auth()->user());
                if (! $result['created']) {
                    throw ValidationException::withMessages(['service_name' => 'Bu hizmet veya eş adı zaten var. Mevcut kaydı düzenleyin.']);
                }
                $service = $result['service'];
            }
            app(\App\Services\SearchDemand\ServiceKeywordService::class)->replace($service, $this->matching_words);
        });
        $this->message = 'Hizmet ve eşleştirme kelimeleri kaydedildi.';
        $this->closeEditor();
        $this->resetPage();
    }

    public function queueBulk(\App\Services\SearchDemand\LibraryImportWorkflow $workflow): void
    {
        $this->validate([
            'bulk_sector' => ['required', Rule::exists('service_categories', 'code')],
            'bulk_text' => ['required', 'string', 'max:500000'],
        ]);
        $workflow->queue('services', ['text' => $this->bulk_text, 'sector' => $this->bulk_sector], auth()->user());
        $this->bulkOpen = false;
        $this->bulk_text = '';
        $this->message = 'Hizmetler sıraya alındı. Sonuçları bu ekrandan veya Aktivite ekranından takip edebilirsiniz.';
    }

    public function addAlias(ServiceCatalogService $catalog): void
    {
        $this->validate(['alias' => ['required', 'string', 'max:255']]);
        $catalog->addAlias(ServiceCatalogItem::query()->findOrFail($this->editingId), $this->alias, app()->getLocale(), auth()->user());
        $this->alias = '';
        $this->message = 'Eş ad kaydedildi.';
    }

    public function removeAlias(int $id, ServiceCatalogService $catalog): void
    {
        $catalog->removeAlias(ServiceCatalogItem::query()->findOrFail($this->editingId), $id);
        $this->message = 'Eş ad kaldırıldı.';
    }

    public function deleteService(int $id, ServiceCatalogService $catalog): void
    {
        $catalog->delete(ServiceCatalogItem::query()->findOrFail($id), auth()->user());
        $this->closeEditor();
        $this->resetPage();
        $this->message = 'Hizmet tüm güncel listelerden kaldırıldı. Silinenler filtresinden geri alabilirsiniz.';
    }

    public function restoreService(int $id, ServiceCatalogService $catalog): void
    {
        $catalog->restore($id, auth()->user());
        $this->message = 'Hizmet ve marka bağlantıları geri alındı.';
        $this->resetPage();
    }

    public function toggleStatus(int $id, ServiceCatalogService $catalog): void
    {
        $service = ServiceCatalogItem::query()->findOrFail($id);
        $catalog->setStatus($service, $service->status === 'active' ? 'archived' : 'active', auth()->user());
        $this->message = 'Hizmet durumu güncellendi.';
    }

    public function manageCategories(): void
    {
        $this->closeEditor();
        $this->reset(['categoryId', 'categoryName']);
        $this->categoriesOpen = true;
    }

    public function editCategory(int $id): void
    {
        $category = ServiceCategory::query()->findOrFail($id);
        $this->categoryId = $id;
        $this->categoryName = $category->name;
        $this->resetValidation();
    }

    public function cancelCategoryEdit(): void
    {
        $this->reset(['categoryId', 'categoryName']);
        $this->resetValidation();
    }

    public function saveCategory(): void
    {
        $this->categoryName = trim($this->categoryName);
        $this->validate(['categoryName' => ['required', 'string', 'max:120']]);
        DB::transaction(function (): void {
            $normalizer = app(IdentityLabelNormalizer::class);
            $key = $normalizer->normalize($this->categoryName);
            $duplicate = ServiceCategory::query()->lockForUpdate()->get()->contains(
                fn ($category) => $category->id !== $this->categoryId && $normalizer->normalize($category->name) === $key
            );
            if ($duplicate) {
                throw ValidationException::withMessages(['categoryName' => 'Bu sektör zaten var.']);
            }
            $category = $this->categoryId !== null
                ? ServiceCategory::query()->lockForUpdate()->findOrFail($this->categoryId)
                : new ServiceCategory(['code' => 'sector_'.Str::uuid()]);
            $category->fill(['name' => $this->categoryName, 'normalized_key' => $key])->save();
        });
        $this->cancelCategoryEdit();
        $this->message = 'Sektör kaydedildi.';
    }

    public function deleteCategory(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $category = ServiceCategory::query()->lockForUpdate()->findOrFail($id);
            ServiceCatalogItem::withTrashed()->where('sector', $category->code)->update(['sector' => null, 'updated_at' => now()]);
            if ($this->sector === $category->code) {
                $this->sector = '';
            }
            \App\Models\SearchQueryLibraryItem::query()->where('sector', $category->code)->update(['sector' => null]);
            $category->delete();
        });
        $this->cancelCategoryEdit();
        $this->resetPage();
        $this->message = 'Sektör silindi. İçindeki hizmetler Kategorisiz altında korunuyor.';
    }

    public function render(): View
    {
        $query = ServiceCatalogItem::query()
            ->with(['primaryName'])->withCount('matchingKeywords')
            ->withCount(['brandOfferings' => fn ($q) => $q->withoutGlobalScope('visible_catalog'), 'searchQueries']);
        if ($this->status === 'deleted') {
            $query->onlyTrashed();
        } elseif (in_array($this->status, ['active', 'archived'], true)) {
            $query->where('status', $this->status);
        }
        if ($this->sector === '__none') {
            $query->whereNull('sector');
        } elseif ($this->sector !== '') {
            $query->where('sector', $this->sector);
        }
        if (trim($this->search) !== '') {
            $term = '%'.app(IdentityLabelNormalizer::class)->normalize($this->search).'%';
            $query->whereHas('names', fn ($names) => $names->withoutGlobalScope('visible_service')->where('is_active', true)->where('normalized_key', 'like', $term));
        }
        $editing = $this->editorOpen && $this->editingId !== null
            ? ServiceCatalogItem::query()->with(['names' => fn ($q) => $q->where('is_active', true)->orderBy('raw_label')])->withCount(['brandOfferings', 'searchQueries'])->find($this->editingId)
            : null;

        return view('livewire.operator.library.service-catalog-page', [
            'services' => $query->orderBy(
                \App\Models\ServiceCatalogName::withoutGlobalScope('visible_service')->select('raw_label')->whereColumn('service_catalog_item_id', 'service_catalog_items.id')
                    ->where('is_primary', true)->where('is_active', true)->limit(1)
            )->orderBy('id')->paginate(25),
            'sectorOptions' => ServiceCategory::options(),
            'categories' => $this->categoriesOpen ? ServiceCategory::query()->orderBy('name')->get() : collect(),
            'editing' => $editing,
            'bulkImports' => \App\Models\SearchQueryLibraryImport::query()->where('source_type', 'services')->latest('id')->limit(5)->get(),
        ]);
    }
}
