<?php

namespace App\Livewire\Demo;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Operator\OperatorPortfolioPresenter;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Global portfolio search for the operator header.
 */
class GlobalSearch extends Component
{
    public string $q = '';

    public bool $open = false;

    public function updatedQ(): void
    {
        $this->open = trim($this->q) !== '';
    }

    public function select(): void
    {
        $this->open = false;
    }

    public function render(): View
    {
        $needle = mb_strtolower(trim($this->q));
        $results = [];

        if ($needle !== '') {
            Customer::query()
                ->orderBy('name')
                ->get()
                ->each(function (Customer $customer) use ($needle, &$results): void {
                    $name = (string) $customer->name;
                    if ($name !== '' && str_contains(mb_strtolower($name), $needle)) {
                        $results[] = [
                            'label' => $name,
                            'meta' => __('operator.nav.customers'),
                            'url' => route('operator.customer', ['customerId' => $customer->id]),
                        ];
                    }
                });

            Brand::query()
                ->with('customer')
                ->orderBy('name')
                ->get()
                ->each(function (Brand $brand) use ($needle, &$results): void {
                    $name = (string) $brand->name;
                    if ($name !== '' && str_contains(mb_strtolower($name), $needle)) {
                        $results[] = [
                            'label' => $name,
                            'meta' => __('operator.nav.brands').' · '.($brand->customer?->name ?? '—'),
                            'url' => route('operator.brand', ['brand' => $brand->id]),
                        ];
                    }
                });

            DigitalAsset::query()
                ->with('brand')
                ->whereNotIn('type', ['domain', 'hosting'])
                ->orderBy('name')
                ->get()
                ->each(function (DigitalAsset $asset) use ($needle, &$results): void {
                    $presented = OperatorPortfolioPresenter::asset($asset);
                    $name = (string) ($presented['name'] ?? '');
                    $type = (string) ($presented['type'] ?? '');
                    $typeLabel = (string) ($presented['type_label'] ?? '');
                    if (($name !== '' && str_contains(mb_strtolower($name), $needle))
                        || ($type !== '' && str_contains(mb_strtolower($type), $needle))
                        || ($typeLabel !== '' && str_contains(mb_strtolower($typeLabel), $needle))) {
                        $results[] = [
                            'label' => $name !== '' ? $name : $typeLabel,
                            'meta' => __('operator.nav.digital_assets').' · '.($presented['brand_name'] ?? '—'),
                            'url' => route($presented['route'], ['assetId' => $asset->id]),
                        ];
                    }
                });
        }

        return view('livewire.demo.global-search', [
            'results' => array_slice($results, 0, 12),
        ]);
    }
}
