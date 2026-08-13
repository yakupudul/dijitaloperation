<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithCustomerForm;
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

            $id = 'c-demo-'.substr(md5($this->name.microtime(true)), 0, 8);
            $payload = $this->customerPayload($id);
            $payload['brands_count'] = 0;
            $payload['digital_assets_count'] = 0;
            $payload['open_findings'] = 0;
            $payload['open_tasks'] = 0;
            $payload['overdue_tasks'] = 0;

            DemoState::addCustomer($payload);

            if ($addBrand) {
                return $this->redirect(route('demo.brand.create', ['customerId' => $id]), navigate: true);
            }

            return $this->redirect(route('demo.customer', ['customerId' => $id]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        return view('livewire.demo.portfolio.customer-form', array_merge($this->customerFormViewData(), [
            'mode' => 'create',
            'pageTitle' => __('operator.portfolio.add_customer'),
            'pageSubtitle' => 'Create the agency relationship first. Brands and digital assets can be added afterwards.',
            'backUrl' => route('demo.customers'),
            'backLabel' => __('operator.nav.customers'),
            'primaryAction' => 'Save customer',
            'showSaveAndAddBrand' => true,
            'industryOptions' => IndustryOptions::options(),
        ]));
    }
}
