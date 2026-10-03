<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\OfferingStatus;
use App\Livewire\Demo\Portfolio\Concerns\InteractsWithBrandForm;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Services\Catalog\BrandCommercialContextService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Markayı düzenle')]
/**
 * Markayı düzenle: name, sector, service areas and optional details. Services are shown read-only with a link: they are
 * edited in one place, Marka › Ayarlar › Marka bilgileri.
 */
class BrandEdit extends Component
{
    use InteractsWithBrandForm;

    public string $brandId = '';

    public function mount(string $brandId): void
    {
        abort_unless(ctype_digit($brandId), 404);
        $brand = Brand::query()->with(['responsibleUsers', 'sectors'])->find($brandId);
        abort_if($brand === null, 404);

        $this->brandId = (string) $brand->id;
        $this->fillBrandForm(array_merge($brand->attributesToArray(), [
            'sector_codes' => $brand->sectorCodes(),
            'responsible_user_ids' => $brand->responsibleUsers->modelKeys(),
        ]));
        $this->fillCommercialContext($brand);
        $this->customerLocked = true;
    }

    public function save(BrandCommercialContextService $commercialContext): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            // Services are not edited here (read-only list), so their rules do not apply.
            $this->validate(array_diff_key($this->brandRules(), array_flip([
                'new_service_sector', 'selected_service_catalog_ids', 'selected_service_catalog_ids.*', 'priority_service_catalog_ids',
                'priority_service_catalog_ids.*', 'new_service_name', 'new_service_is_priority',
            ])));

            $brand = Brand::query()->find($this->brandId);
            abort_if($brand === null, 404);

            DB::transaction(function () use ($brand, $commercialContext): void {
                $brand->fill($this->brandEloquentPayload());
                $brand->save();
                $this->syncBrandSectors($brand);
                $brand->responsibleUsers()->sync($this->sanitizedResponsibleUserIds());
                $commercialContext->sync(
                    $brand,
                    $this->selected_service_catalog_ids,
                    $this->priority_service_catalog_ids,
                    $this->service_areas,
                    $this->new_service_name,
                    $this->new_service_is_priority,
                    auth()->user(),
                    customServiceSector: $this->new_service_sector,
                    allowedSectorCodes: $this->selected_sector_codes,
                    syncServices: false,
                );
            });

            DemoState::flash(__('operator.forms.brand_updated'));

            return $this->redirect(route('operator.brand', ['brand' => $brand->id]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.brand-form', array_merge($this->brandFormViewData(), [
            'mode' => 'edit',
            'pageTitle' => __('operator.forms.edit_brand'),
            'pageSubtitle' => __('operator.forms.edit_brand_subtitle'),
            'backUrl' => route('operator.brand', ['brand' => $this->brandId]),
            'primaryAction' => __('operator.forms.save_changes'),
            'brandId' => $this->brandId,
            'currentServices' => BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', (int) $this->brandId)
                ->where('status', OfferingStatus::Active->value)->get()
                ->map(fn (BrandOffering $o): array => ['name' => $o->displayName(), 'main' => $o->isMain()])
                ->sortBy(fn (array $s): string => ($s['main'] ? '0' : '1').$s['name'])->values()->all(),
        ]));
    }
}
