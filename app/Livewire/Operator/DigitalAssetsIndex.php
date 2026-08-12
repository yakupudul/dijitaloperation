<?php

namespace App\Livewire\Operator;

use App\Models\DigitalAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Digital Assets')]
class DigitalAssetsIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $brand = null;

    #[Url]
    public string $type = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $assets = DigitalAsset::query()
            ->with('brand.customer')
            ->when($this->brand !== null, fn ($q) => $q->where('brand_id', $this->brand))
            ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')
            ->paginate(15);

        return view('livewire.operator.digital-assets-index', [
            'assets' => $assets,
        ]);
    }
}
