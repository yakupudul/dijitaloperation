<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Customer')]
class CustomerDetail extends Component
{
    public string $customerId = '';

    public function mount(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function render(): View
    {
        $customers = DemoState::all()['customers'];
        $customer = collect($customers)->firstWhere('id', $this->customerId) ?? DemoCatalog::customer();
        $brands = collect(DemoState::all()['brands'])
            ->filter(fn (array $b): bool => ($b['customer_id'] ?? '') === ($customer['id'] ?? ''))
            ->values()
            ->all();

        return view('livewire.demo.portfolio.customer-detail', [
            'customer' => $customer,
            'brands' => $brands !== [] ? $brands : [DemoCatalog::brand()],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
