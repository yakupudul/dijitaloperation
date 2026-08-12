<?php

namespace App\Livewire\Operator;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.operator.dashboard', [
            'customerCount' => Customer::query()->count(),
            'brandCount' => Brand::query()->count(),
            'assetCount' => DigitalAsset::query()->count(),
            'metaAssetCount' => DigitalAsset::query()->where('type', 'meta_ads')->count(),
        ]);
    }
}
