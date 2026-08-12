<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoState;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Brands')]
class BrandsIndex extends Component
{
    public function render(): View
    {
        $brands = collect(DemoState::all()['brands'] ?? [])
            ->map(fn (array $brand): array => DemoState::normalizeBrand($brand))
            ->map(function (array $brand): array {
                $brand['sector_label'] = IndustryOptions::label($brand['sector'] ?? null);

                return $brand;
            })
            ->values()
            ->all();

        return view('livewire.demo.portfolio.brands-index', [
            'brands' => $brands,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
