<?php

namespace App\Livewire\Operator;

use App\Models\Brand;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Brands')]
class BrandsIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $customer = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $brands = Brand::query()
            ->with('customer')
            ->withCount('digitalAssets')
            ->when($this->customer !== null, fn ($q) => $q->where('customer_id', $this->customer))
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.operator.brands-index', [
            'brands' => $brands,
        ]);
    }
}
