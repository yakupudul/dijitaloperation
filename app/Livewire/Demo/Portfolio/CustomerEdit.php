<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithCustomerForm;
use App\Models\Customer;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Edit customer')]
class CustomerEdit extends Component
{
    use InteractsWithCustomerForm;

    public string $customerId = '';

    public function mount(string $customerId): void
    {
        abort_unless(ctype_digit($customerId), 404);

        $customer = Customer::query()->with('responsibleUsers')->find($customerId);
        abort_if($customer === null, 404);

        $this->customerId = (string) $customer->id;
        $this->fillCustomerForm(OperatorPortfolioPresenter::customer($customer));
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->customerRules(), [], $this->customerValidationAttributes());

            $customer = Customer::query()->find($this->customerId);
            abort_if($customer === null, 404);

            $payload = $this->customerPayload();
            unset($payload['id'], $payload['responsible_user_ids']);
            $customer->fill($payload);
            $customer->syncServices(array_values($this->services));
            $customer->save();
            $customer->responsibleUsers()->sync($this->sanitizedResponsibleUserIds());

            DemoState::flash(__('operator.forms.customer_updated'));

            return $this->redirect(route('operator.customer', ['customerId' => $customer->id]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.customer-form', array_merge($this->customerFormViewData(), [
            'mode' => 'edit',
            'pageTitle' => __('operator.forms.edit_customer'),
            'pageSubtitle' => __('operator.forms.edit_customer_subtitle'),
            'backUrl' => route('operator.customer', ['customerId' => $this->customerId]),
            'backLabel' => __('operator.nav.customers'),
            'primaryAction' => __('operator.forms.save_changes'),
            'showSaveAndAddBrand' => false,
        ]));
    }
}
