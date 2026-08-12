<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Portfolio\Concerns\InteractsWithBrandForm;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
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
            $this->customer_id = $this->customerId;
            $this->customerLocked = true;
        } elseif (DemoState::findCustomer(DemoCatalog::CUSTOMER_ID)) {
            $this->customer_id = DemoCatalog::CUSTOMER_ID;
        }
    }

    public function save(): mixed
    {
        if ($this->saving) {
            return null;
        }

        $this->saving = true;

        try {
            $this->validate($this->brandRules());

            $id = 'b-demo-'.substr(md5($this->name.microtime(true)), 0, 8);
            $payload = $this->brandPayload($id);
            DemoState::addBrand($payload);

            return $this->redirect(route('demo.brand', ['brand' => $id]), navigate: true);
        } finally {
            $this->saving = false;
        }
    }

    public function render(): View
    {
        $backUrl = $this->customerLocked
            ? route('demo.customer', ['customerId' => $this->customer_id, 'tab' => 'brands'])
            : route('demo.brands');

        return view('livewire.demo.portfolio.brand-form', array_merge($this->brandFormViewData(), [
            'mode' => 'create',
            'pageTitle' => 'Add brand',
            'pageSubtitle' => 'Create a brand under a customer. Digital assets can be connected afterwards.',
            'backUrl' => $backUrl,
            'primaryAction' => 'Save brand',
        ]));
    }
}
