<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithCustomerForm;
use App\Support\Demo\DemoCatalog;
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
        $this->customerId = $customerId;
        $customer = DemoState::findCustomer($customerId) ?? DemoCatalog::customer();
        $this->fillCustomerForm($customer);
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->customerRules(), [], $this->customerValidationAttributes());

            $existing = DemoState::findCustomer($this->customerId) ?? DemoCatalog::customer();
            $payload = array_merge($existing, $this->customerPayload($this->customerId));
            DemoState::updateCustomer($this->customerId, $payload);

            return $this->redirect(route('demo.customer', ['customerId' => $this->customerId]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.customer-form', array_merge($this->customerFormViewData(), [
            'mode' => 'edit',
            'pageTitle' => 'Edit customer',
            'pageSubtitle' => 'Update the agency relationship profile.',
            'backUrl' => route('demo.customer', ['customerId' => $this->customerId]),
            'backLabel' => 'Customer',
            'primaryAction' => 'Save changes',
            'showSaveAndAddBrand' => false,
        ]));
    }
}
