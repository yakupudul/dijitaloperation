<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithBrandForm;
use App\Models\Brand;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Edit brand')]
class BrandEdit extends Component
{
    use InteractsWithBrandForm;

    public string $brandId = '';

    public function mount(string $brandId): void
    {
        abort_unless(ctype_digit($brandId), 404);
        $brand = Brand::query()->with('responsibleUsers')->find($brandId);
        abort_if($brand === null, 404);

        $this->brandId = (string) $brand->id;
        $this->fillBrandForm(OperatorPortfolioPresenter::brand($brand));
        $this->customerLocked = true;
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->brandRules());

            $brand = Brand::query()->find($this->brandId);
            abort_if($brand === null, 404);

            $brand->fill($this->brandEloquentPayload());
            $brand->save();
            $brand->responsibleUsers()->sync($this->sanitizedResponsibleUserIds());

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
        ]));
    }
}
