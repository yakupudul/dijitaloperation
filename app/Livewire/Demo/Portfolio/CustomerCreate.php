<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithCustomerForm;
use App\Models\Customer;
use App\Support\Demo\DemoState;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Add customer')]
class CustomerCreate extends Component
{
    use InteractsWithCustomerForm;

    public function mount(): void
    {
        $this->status = 'active';
        $this->type = 'company';
    }

    public function save(bool $addBrand = false): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->customerRules(), [], $this->customerValidationAttributes());

            $payload = $this->customerPayload();
            unset($payload['id'], $payload['responsible_user_ids']);

            $customer = Customer::query()->create($payload);
            $customer->syncServices(array_values($this->services));
            $customer->responsibleUsers()->sync($this->sanitizedResponsibleUserIds());
            $customer->save();

            DemoState::flash(__('operator.forms.customer_saved', ['name' => $customer->name]));

            if ($addBrand) {
                return $this->redirect(route('operator.brand.create', ['customerId' => $customer->id]), navigate: true);
            }

            return $this->redirect(route('operator.customer', ['customerId' => $customer->id]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.customer-form', array_merge($this->customerFormViewData(), [
            'mode' => 'create',
            'pageTitle' => __('operator.portfolio.add_customer'),
            'pageSubtitle' => __('operator.forms.add_customer_subtitle'),
            'backUrl' => route('operator.customers'),
            'backLabel' => __('operator.nav.customers'),
            'primaryAction' => __('operator.forms.save_customer'),
            'showSaveAndAddBrand' => true,
            'industryOptions' => IndustryOptions::options(),
        ]));
    }
}
