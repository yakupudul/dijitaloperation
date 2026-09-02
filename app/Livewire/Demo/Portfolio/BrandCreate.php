<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithBrandForm;
use App\Models\Brand;
use App\Models\Customer;
use App\Services\SearchDemand\BrandCommercialContextService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Add brand')]
class BrandCreate extends Component
{
    use InteractsWithBrandForm;

    #[Url]
    public string $customerId = '';

    public function mount(): void
    {
        if ($this->customerId !== '') {
            abort_unless(ctype_digit($this->customerId), 404);
            abort_if(Customer::query()->find($this->customerId) === null, 404);
            $this->customer_id = $this->customerId;
            $this->customerLocked = true;
        }
    }

    public function save(BrandCommercialContextService $commercialContext): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->brandRules());

            $brand = DB::transaction(function () use ($commercialContext): Brand {
                $brand = Brand::query()->create($this->brandEloquentPayload());
                $brand->responsibleUsers()->sync($this->sanitizedResponsibleUserIds());
                $commercialContext->sync(
                    $brand,
                    $this->selected_service_catalog_ids,
                    $this->priority_service_catalog_ids,
                    $this->service_areas,
                    $this->new_service_name,
                    $this->new_service_is_priority,
                    auth()->user(),
                );

                return $brand;
            });

            DemoState::flash(__('operator.forms.brand_saved', ['name' => $brand->name]));

            return $this->redirect(route('operator.brand', ['brand' => $brand->id]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        $backUrl = $this->customerLocked
            ? route('operator.customer', ['customerId' => $this->customer_id, 'tab' => 'brands'])
            : route('operator.brands');

        return view('livewire.demo.portfolio.brand-form', array_merge($this->brandFormViewData(), [
            'mode' => 'create',
            'pageTitle' => __('operator.forms.add_brand'),
            'pageSubtitle' => __('operator.forms.add_brand_subtitle'),
            'backUrl' => $backUrl,
            'primaryAction' => __('operator.forms.save_brand'),
        ]));
    }
}
